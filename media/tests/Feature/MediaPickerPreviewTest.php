<?php

declare(strict_types=1);

use Livewire\Livewire;
use Quansitech\Cmf\Media\Tests\Fixtures\Forms\MediaPickerForm;

/*
 * MediaPicker 已选媒体的查看能力：点击图片弹窗预览大图、视频/音频内嵌播放、
 * 其他类型弹窗给出新窗口下载链接（是否落盘由对象 Content-Disposition 元数据决定）。
 * 服务端渲染的存量项与 JS 上传新增的项都带 url/mime/name 数据属性，
 * 点击项（除删除按钮外）统一经 openPreview 分发。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    ]]);
});

it('renders preview data attributes on server-rendered items', function (): void {
    actingAsTestUser();
    $media = createMedia(['mime' => 'video/mp4', 'ext' => 'mp4', 'original_name' => 'clip.mp4']);

    $html = Livewire::test(MediaPickerForm::class)
        ->set('data.photos', [$media->id])
        ->html();

    expect($html)->toContain('data-cmf-media-mime="video/mp4"')
        ->and($html)->toContain('data-cmf-media-name="clip.mp4"')
        ->and($html)->toContain('data-cmf-media-url=')
        // 预览弹窗始终渲染（与媒体库弹窗开关无关）
        ->and($html)->toContain('data-cmf-media-preview-modal')
        ->and($html)->toContain('data-cmf-media-preview-body');
});

it('wires the preview flow through the view and JS', function (): void {
    $view = file_get_contents(__DIR__.'/../../resources/views/forms/components/media-picker.blade.php');
    $script = file_get_contents(__DIR__.'/../../resources/js/direct-upload.js');

    expect($view)
        ->toContain('data-cmf-media-url')
        ->toContain('data-cmf-media-mime')
        ->toContain('data-cmf-media-preview-modal')
        ->toContain('data-cmf-media-preview-close')
        ->and($script)
        // 图片预览 / 视频音频播放 / 其他类型下载链接三类分发
        ->toContain("mime.indexOf('image/') === 0")
        ->toContain("mime.indexOf('video/') === 0")
        ->toContain("mime.indexOf('audio/') === 0")
        ->toContain('openPreview(')
        // 上传新增的项同样带预览数据
        ->toContain('li.dataset.cmfMediaUrl')
        // 关闭时清空 body 停止音视频
        ->toContain('previewBody.innerHTML = \'\';');
});
