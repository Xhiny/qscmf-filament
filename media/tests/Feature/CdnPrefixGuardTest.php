<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Support\MediaUploader;

/**
 * CDN 子目录关联（cdn.path_prefix）的上传期守卫：对象落点不在该前缀下时，
 * 签发与落盘都直接 422，而不是存进库后让 url() 出口恒 null。
 */
beforeEach(function (): void {
    config([
        'cmf-media.default' => 'cos',
        'filesystems.disks.cmf-media-cos.cdn' => [
            'url' => 'https://cdn.example.com',
            'path_prefix' => 't4tstudio',
        ],
        'cmf-media.rules' => [
            't4t' => ['mimes' => ['image/*'], 'path' => 't4tstudio'],
        ],
    ]);
    Storage::fake('cmf-media-cos');
});

it('sign rejects a key outside the cdn path prefix', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('d', 32),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('path');
});

it('sign keeps the key when the rule path sits inside the prefix', function (): void {
    actingAsTestUser();

    $hash = str_repeat('f', 32);

    $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
        'rule' => 't4t',
    ])->assertOk()
        ->assertJsonPath('path', 't4tstudio/ff/'.$hash.'.jpg');
});

it('store rejects a key outside the prefix before touching the disk', function (): void {
    $file = UploadedFile::fake()->image('photo.jpg', 10, 10);

    expect(fn () => app(MediaUploader::class)->store($file))
        ->toThrow(ValidationException::class);

    expect(Storage::disk('cmf-media-cos')->allFiles())->toBe([]);
});

it('skips the guard when the cdn segment is not actually in use', function (): void {
    actingAsTestUser();

    // 只设了前缀、CDN 域名与鉴权都没配：adapter 不换，url() 走原生全 Key，桶根对象可用
    config()->set('filesystems.disks.cmf-media-cos.cdn', ['path_prefix' => 't4tstudio']);

    $hash = str_repeat('a', 32);

    $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
    ])->assertOk()
        ->assertJsonPath('path', 'aa/'.$hash.'.jpg');
});
