<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\RichContent;

use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use LogicException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * RichEditor 文件附件接管（Filament v5 FileAttachmentProvider）：
 *
 * - 上传：编辑器拖入/粘贴/附件工具上传的文件经 MediaUploader 服务端建档，
 *   内容哈希去重（同文件重复插入复用同一 media 记录），附件 id 即 media id；
 * - 引用计数：表单保存时 Filament 回调 cleanUpFileAttachments() 传入当前内容
 *   仍在引用的附件 id 集合，按字段 syncMedia 差集增删 usages（ref_count 增减）；
 * - 清零删除：复用既有链路（归零软删 + 延迟 DeleteMediaJob 清理云端对象）；
 * - 上传后未保存表单的孤儿文件，由每日 cmf-media:prune-orphans 兜底清理。
 *
 * 宿主模型需 use HasMedia（提供 syncMedia）并实现 Filament 的 HasRichContent
 * 接口，可直接 use HasMediaRichContent trait 一行接入。
 */
class MediaFileAttachmentProvider implements FileAttachmentProvider
{
    protected ?RichContentAttribute $attribute = null;

    public function __construct(
        protected MediaUploader $uploader,
    ) {}

    public function attribute(RichContentAttribute $attribute): static
    {
        if (! method_exists($attribute->getModel(), 'syncMedia')) {
            throw new LogicException(
                'MediaFileAttachmentProvider 要求宿主模型 use Quansitech\Cmf\Media\Concerns\HasMedia（引用计数依赖 syncMedia）：'
                .$attribute->getModel()::class
            );
        }

        $this->attribute = $attribute;

        return $this;
    }

    /**
     * 附件 id（media id）→ 访问 URL。含软删记录：延迟清理窗口内内容仍可显示；
     * 重新被引用时计数事件里会恢复记录。
     */
    public function getFileAttachmentUrl(mixed $file): ?string
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        /** @var Media|null $media */
        $media = $model::withTrashed()->find($file);

        return $media?->url();
    }

    /**
     * 保存上传附件：服务端计算内容 hash → 命中已有记录直接复用（秒传去重），
     * 未命中落盘建档。返回 media id 字符串作为编辑器图片节点的附件 id。
     *
     * cmf-media.rich_content.rule 可选声明入口规则（如含 path 的规则），
     * 未配置 = 沿用全局限制（存量行为）。
     */
    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): mixed
    {
        /** @var mixed $ruleName */
        $ruleName = config('cmf-media.rich_content.rule');

        $media = $this->uploader->store(
            $file,
            uploaderId: Auth::user()?->getAuthIdentifier(),
            rule: UploadRule::find(is_string($ruleName) ? $ruleName : null),
        );

        return (string) $media->id;
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return 'public';
    }

    /**
     * 新建记录尚无 id 无法挂引用：附件保存与引用同步延迟到记录创建后
     * （Filament 在 saveRelationships 阶段回调 saveFileAttachmentsToRecord）。
     */
    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return true;
    }

    /**
     * 保存时以当前内容中的附件 id 集合覆盖该字段的引用（差集增删）：
     * 新增 attach（ref_count +1）、移除 detach（-1，归零触发自动删除链路）。
     *
     * @param  array<mixed>  $exceptIds
     */
    public function cleanUpFileAttachments(array $exceptIds): void
    {
        $model = $this->attribute?->getModel();

        if (! $model instanceof Model || ! $model->exists) {
            return;
        }

        // 宿主模型须 use HasMedia（attribute() 已先行校验，此处防御未持久化场景）
        $syncMedia = [$model, 'syncMedia'];

        if (! is_callable($syncMedia)) {
            return;
        }

        /** @var list<int|string> $exceptIds */
        $syncMedia($exceptIds, $this->attribute->getName());
    }
}
