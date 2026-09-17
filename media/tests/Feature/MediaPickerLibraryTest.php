<?php

declare(strict_types=1);

use Livewire\Livewire;
use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;
use Quansitech\Cmf\Media\Tests\Fixtures\Forms\MediaPickerForm;

/*
 * MediaPicker「从媒体库选择」入口默认隐藏（cmf-media.picker_library）：
 * 隐藏时按钮与弹窗均不渲染，避免普通用户直接复用全站媒体库文件；
 * 开启 config 后恢复渲染，JS 侧对按钮缺失已做防御（不中断初始化）。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    ]]);
});

it('defaults the library entry to hidden', function (): void {
    expect(MediaPicker::make('photos')->canOpenLibrary())->toBeFalse();
});

it('hides the library button and modal by default', function (): void {
    actingAsTestUser();

    $html = Livewire::test(MediaPickerForm::class)->html();

    expect($html)->not->toContain('data-cmf-media-library-btn')
        ->and($html)->not->toContain('data-cmf-media-modal')
        // 上传入口不受影响
        ->and($html)->toContain('data-cmf-media-input');
});

it('renders the library entry when the config switch is on', function (): void {
    config(['cmf-media.picker_library' => true]);
    actingAsTestUser();

    expect(MediaPicker::make('photos')->canOpenLibrary())->toBeTrue();

    $html = Livewire::test(MediaPickerForm::class)->html();

    // 表单内两个 picker 均渲染按钮与弹窗
    expect(substr_count($html, 'data-cmf-media-library-btn'))->toBe(2)
        ->and(substr_count($html, 'data-cmf-media-modal'))->toBeGreaterThanOrEqual(2);
});

it('guards the JS against a missing library button', function (): void {
    expect(file_get_contents(__DIR__.'/../../resources/js/direct-upload.js'))
        ->toContain('if (libraryBtn)');
});
