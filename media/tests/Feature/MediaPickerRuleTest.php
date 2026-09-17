<?php

declare(strict_types=1);

use Livewire\Livewire;
use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;
use Quansitech\Cmf\Media\Tests\Fixtures\Forms\MediaPickerForm;

/*
 * MediaPicker 入口规则声明 → 视图/选择行为约束（XSDA-7）：
 * 声明规则的字段输出 accept 类型过滤（文件选择阶段即生效）与规则名
 * （JS 透传给服务端）；未声明的字段保持原样。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    ]]);
});

it('resolves the declared upload rule on the component', function (): void {
    $picker = MediaPicker::make('photos')->uploadRule('img-10');

    expect($picker->getUploadRuleName())->toBe('img-10')
        ->and($picker->getUploadRule()?->maxSize)->toBe(10 * 1024 * 1024)
        ->and($picker->getUploadRule()?->acceptAttribute())->toBe('image/*');

    expect(MediaPicker::make('plain')->getUploadRule())->toBeNull();
});

it('supports closure rules evaluated in context', function (): void {
    $picker = MediaPicker::make('photos')->uploadRule(fn (): string => 'img-10');

    expect($picker->getUploadRuleName())->toBe('img-10');
});

it('renders rule constraints on the picker input', function (): void {
    actingAsTestUser();

    $html = Livewire::test(MediaPickerForm::class)->html();

    // 声明规则的字段：accept 选择期过滤 / 规则名 / 入口大小上限
    expect($html)->toContain('data-rule="img-10"')
        ->toContain('accept="image/*"')
        ->toContain('data-max-size="'.(10 * 1024 * 1024).'"');
});

it('leaves pickers without a rule untouched （存量行为不变，验收 5）', function (): void {
    actingAsTestUser();

    $html = Livewire::test(MediaPickerForm::class)->html();

    // 未声明规则的 legacy 字段：无 accept/data-rule，大小上限为全局值
    expect($html)->toContain('data-state-path="data.legacy"')
        ->and(substr_count($html, 'data-rule='))->toBe(1)
        ->and(substr_count($html, 'accept='))->toBe(1)
        ->and($html)->toContain('data-max-size="'.((int) config('cmf-media.max_size')).'"');
});
