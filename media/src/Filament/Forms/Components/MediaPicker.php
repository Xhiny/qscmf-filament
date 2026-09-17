<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use InvalidArgumentException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * 媒体选择/直传字段：浏览器直传云存储（hash → 查重 → 签名 → 直传 → 回调），
 * 支持单/多选、预览已有媒体、从媒体库选择（默认隐藏，cmf-media.picker_library 开启）。
 *
 * 声明 cropAspectRatio() 后，选中的图片会先在浏览器里按该比例裁好再上传：
 * 裁剪发生在计算内容指纹之前，去重与引用计数都基于裁剪后的文件。
 *
 * 字段 state 为媒体 id（单选）或 id 数组（多选）；业务模型保存后调用
 * HasMedia::syncMedia($ids, $field) 建立引用并驱动引用计数。
 *
 * uploadRule('名') 声明本入口使用的上传规则（cmf-media.rules）：服务端全链路
 * 强制校验类型/大小，前端同步约束选择行为（accept 类型过滤在文件选择阶段
 * 即生效）。未声明时沿用全局限制，存量行为不变。
 */
class MediaPicker extends Field
{
    protected string $view = 'cmf-media::forms.components.media-picker';

    protected bool|Closure $multiple = false;

    protected string|Closure|null $uploadRule = null;

    protected float|Closure|null $cropAspectRatio = null;

    protected int|Closure|null $cropMaxWidth = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 多选时 state 归一化为 int 数组；单选为 int|null
        $this->dehydrateStateUsing(function (mixed $state): mixed {
            if ($this->isMultiple()) {
                return collect(is_array($state) ? $state : [$state])
                    ->filter(fn (mixed $id): bool => filled($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
            }

            return filled($state) ? (int) $state : null;
        });
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->multiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->multiple);
    }

    /**
     * 声明本入口使用的上传规则（cmf-media.rules 中的名称）。
     */
    public function uploadRule(string|Closure|null $name): static
    {
        $this->uploadRule = $name;

        return $this;
    }

    /**
     * 锁定的裁剪比例，接受 '4:3'、'4/3'、'1.5' 或 1.5；null 表示不裁剪。
     */
    public function cropAspectRatio(float|string|Closure|null $ratio): static
    {
        $this->cropAspectRatio = $ratio instanceof Closure
            ? $ratio
            : static::normalizeAspectRatio($ratio);

        return $this;
    }

    public function getUploadRuleName(): ?string
    {
        $name = $this->evaluate($this->uploadRule);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 解析入口规则；未知名称/超出全局白名单视为配置错误，抛 InvalidArgumentException。
     */
    public function getUploadRule(): ?UploadRule
    {
        return UploadRule::find($this->getUploadRuleName());
    }

    /**
     * 是否渲染「从媒体库选择」入口（按钮与弹窗）。全局开关
     * cmf-media.picker_library 控制，默认隐藏。
     */
    public function canOpenLibrary(): bool
    {
        return (bool) config('cmf-media.picker_library', false);
    }

    /**
     * 裁剪产物的最大像素宽，超出则等比缩小；不给则用 config('cmf-media.crop.max_width')。
     */
    public function cropMaxWidth(int|Closure|null $width): static
    {
        $this->cropMaxWidth = $width;

        return $this;
    }

    public function getCropAspectRatio(): ?float
    {
        $ratio = $this->evaluate($this->cropAspectRatio);

        return $ratio === null ? null : static::normalizeAspectRatio($ratio);
    }

    public function getCropMaxWidth(): int
    {
        $width = $this->evaluate($this->cropMaxWidth);

        return (int) ($width ?? config('cmf-media.crop.max_width'));
    }

    /**
     * 裁剪产物的 JPEG 重编码质量；PNG 保持无损，该值对它不生效。
     */
    public function getCropQuality(): float
    {
        return (float) config('cmf-media.crop.quality', 0.92);
    }

    /**
     * 把比例归一化成浮点数，顺带在配置写错时尽早报错。
     */
    protected static function normalizeAspectRatio(int|float|string|null $ratio): ?float
    {
        if ($ratio === null) {
            return null;
        }

        // 数值写法同样要校验：静默放行负数会让 JS 侧的 (ratio > 0) 判定悄悄跳过裁剪
        if (is_int($ratio) || is_float($ratio)) {
            if ($ratio <= 0) {
                throw new InvalidArgumentException("裁剪比例「{$ratio}」必须大于 0。");
            }

            return (float) $ratio;
        }

        $parts = preg_split('#[:/]#', $ratio);

        if (count($parts) === 2) {
            [$width, $height] = array_map('trim', $parts);

            // 逐段校验：floatval('3px') 会静默得到 3，误写要报错而不是将错就错
            if (! is_numeric($width) || ! is_numeric($height)) {
                throw new InvalidArgumentException("裁剪比例「{$ratio}」无法解析，请用 '4:3' 或 1.33 这样的写法。");
            }

            if ((float) $width <= 0 || (float) $height <= 0) {
                throw new InvalidArgumentException("裁剪比例「{$ratio}」的宽高必须大于 0。");
            }

            return (float) $width / (float) $height;
        }

        // 单个数字（'1.5'）才按数值解析；'4:3:2' 这类误写必须报错而不是静默取 4
        if (is_numeric(trim($ratio)) && (float) $ratio > 0) {
            return (float) $ratio;
        }

        throw new InvalidArgumentException("裁剪比例「{$ratio}」无法解析，请用 '4:3' 或 1.33 这样的写法。");
    }

    /**
     * 当前 state 对应的媒体记录（视图预览用）。
     *
     * @return \Illuminate\Support\Collection<int, Media>
     */
    public function getSelectedMedia(): \Illuminate\Support\Collection
    {
        $state = $this->getState();
        $ids = is_array($state) ? $state : [$state];
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return collect();
        }

        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        return $model::query()->whereIn('id', $ids)->get()->keyBy('id')
            ->pipe(fn (\Illuminate\Support\Collection $collection): \Illuminate\Support\Collection => collect($ids)
                ->map(fn (int $id): ?Media => $collection->get($id))
                ->filter()
                ->values());
    }
}
