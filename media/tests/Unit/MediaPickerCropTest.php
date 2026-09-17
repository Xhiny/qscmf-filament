<?php

declare(strict_types=1);

/**
 * 裁剪比例的解析与输出上限。
 *
 * 比例写错只会在浏览器里表现成「裁出来不对」，PHP 侧不会报错，所以在这里把
 * 写法约定与容错固化下来。
 */

use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;

it('accepts a ratio written as W:H, W/H or a plain number', function (): void {
    expect(MediaPicker::make('cover')->cropAspectRatio('4:3')->getCropAspectRatio())->toBe(4 / 3)
        ->and(MediaPicker::make('cover')->cropAspectRatio('16/9')->getCropAspectRatio())->toBe(16 / 9)
        ->and(MediaPicker::make('cover')->cropAspectRatio('1.5')->getCropAspectRatio())->toBe(1.5)
        ->and(MediaPicker::make('cover')->cropAspectRatio(1.5)->getCropAspectRatio())->toBe(1.5)
        ->and(MediaPicker::make('cover')->cropAspectRatio('1:1')->getCropAspectRatio())->toBe(1.0);
});

it('keeps cropping off unless a ratio is declared', function (): void {
    expect(MediaPicker::make('cover')->getCropAspectRatio())->toBeNull();
});

it('rejects a non-positive ratio written as a number', function (int|float $ratio): void {
    expect(fn (): MediaPicker => MediaPicker::make('cover')->cropAspectRatio($ratio))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '负数' => -1.5,
    '零' => 0,
    '零（浮点）' => 0.0,
]);

it('rejects a malformed ratio instead of guessing', function (string $ratio): void {
    expect(fn () => MediaPicker::make('cover')->cropAspectRatio($ratio))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '多段冒号' => '4:3:2',
    '宽为零' => '0:3',
    '高为零' => '4:0',
    '负数' => '-4:3',
    '非数字' => 'abc',
    '带单位' => '4:3px',
]);

it('falls back to the configured output width and allows an override', function (): void {
    expect(MediaPicker::make('cover')->getCropMaxWidth())
        ->toBe((int) config('cmf-media.crop.max_width'))
        ->and(MediaPicker::make('cover')->cropMaxWidth(800)->getCropMaxWidth())->toBe(800);
});

it('exposes the configured JPEG quality for the view', function (): void {
    expect(MediaPicker::make('cover')->getCropQuality())
        ->toBe((float) config('cmf-media.crop.quality', 0.92));
});

it('evaluates a closure ratio so it can follow live form state', function (): void {
    $field = MediaPicker::make('cover')->cropAspectRatio(fn (): string => '3:2');

    expect($field->getCropAspectRatio())->toBe(1.5);
});
