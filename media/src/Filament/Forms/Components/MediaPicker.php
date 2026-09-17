<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * 媒体选择/直传字段：浏览器直传云存储（hash → 查重 → 签名 → 直传 → 回调），
 * 支持单/多选、预览已有媒体、从媒体库选择（默认隐藏，cmf-media.picker_library 开启）。
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
