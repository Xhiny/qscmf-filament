<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * 入口规则静态目录（rules.*.path）全链路：
 *
 * - sign：签名响应的 path / upload_url 携带目录段（客户端无路径决定权）；
 * - callback：带目录段 path 建档成功，全等防伪对伪造目录段拒绝；
 * - 判重不变：同内容跨入口（无 path → 带 path）秒传命中复用首传记录，
 *   path 列冻结首传值（绕 check 直传的 callback 同样命中复用）；
 * - local 驱动：多级目录段由存储层递归建目录落盘。
 */
beforeEach(function (): void {
    config(['cmf-media.rules' => [
        't4t' => ['mimes' => ['image/*'], 'path' => 't4tstudio'],
        'deep' => ['mimes' => ['image/*'], 'path' => 'A/B/C'],
    ]]);
    Storage::fake('cmf-media-tos');
});

it('sign issues a key carrying the rule path （目录段由服务端下发）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('c', 32);

    $response = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
        'rule' => 't4t',
    ])->assertOk();

    expect($response->json('path'))->toBe('t4tstudio/cc/'.$hash.'.jpg')
        ->and($response->json('upload_url'))->toContain('t4tstudio/cc/'.$hash.'.jpg');
});

it('sign keeps the legacy key for rules without path （存量行为）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('e', 32);

    $response = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
    ])->assertOk();

    expect($response->json('path'))->toBe('ee/'.$hash.'.jpg');
});

it('creates a media record on callback with the rule path', function (): void {
    actingAsTestUser();

    $hash = str_repeat('a', 32);
    $path = Media::objectKey($hash, 'jpg', '', 't4tstudio');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
        'rule' => 't4t',
    ])->assertOk()
        ->assertJsonPath('media.path', $path)
        ->assertJsonPath('media.hash', $hash);
});

it('rejects callback paths whose directory segment does not match the rule （全等防伪）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('f', 32);
    // 客户端自造目录段：规则声明 t4tstudio，回传 fake-dir
    $path = 'fake-dir/ff/'.$hash.'.jpg';
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
        'rule' => 't4t',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('path');

    expect(Media::where('hash', $hash)->exists())->toBeFalse();
});

it('dedupes across entries: check hits the first-upload record regardless of path （判重不变）', function (): void {
    actingAsTestUser();

    // 先经无规则入口建档（桶根）
    $hash = str_repeat('1', 32);
    $rootKey = Media::objectKey($hash, 'jpg');
    $media = createMedia(['hash' => $hash, 'path' => $rootKey]);

    // 再经 t4t 入口探测同内容 → 命中复用，path 保持首传值
    $this->postJson(route('cmf-media.check'), [
        'hash' => $hash,
        'size' => 100,
        'mime' => 'image/jpeg',
        'rule' => 't4t',
    ])->assertOk()
        ->assertJsonPath('media.id', $media->id)
        ->assertJsonPath('media.path', $rootKey);
});

it('callback reuses the existing record when a duplicate bypasses check （首传冻结）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('2', 32);
    $rootKey = Media::objectKey($hash, 'jpg');
    createMedia(['hash' => $hash, 'path' => $rootKey]);

    // 异常客户端绕过 check 直传到 t4t 目录后回调
    $path = Media::objectKey($hash, 'jpg', '', 't4tstudio');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
        'rule' => 't4t',
    ])->assertOk()
        ->assertJsonPath('media.path', $rootKey);

    expect(Media::where('hash', $hash)->count())->toBe(1);
});

it('stores local uploads under the multi-level rule path （递归目录落盘）', function (): void {
    config(['cmf-media.default' => 'local']);
    Storage::fake('cmf-media-local');
    $this->withHeaders(['Accept' => 'application/json']);
    actingAsTestUser();

    $file = UploadedFile::fake()->image('photo.jpg', 120, 80);
    $hash = md5($file->get());
    $expectedKey = 'A/B/C/'.substr($hash, 0, 2).'/'.$hash.'.jpg';

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => $hash,
        'name' => 'photo.jpg',
        'rule' => 'deep',
    ])->assertOk()
        ->assertJsonPath('media.path', $expectedKey);

    expect(Storage::disk('cmf-media-local')->exists($expectedKey))->toBeTrue();
});

it('re-uploading the same file via a path rule leaves no orphan object（跨目录重复不产生孤儿）', function (): void {
    actingAsTestUser();
    $this->withHeaders(['Accept' => 'application/json']);
    Storage::fake('cmf-media-local');
    config(['cmf-media.default' => 'local']);

    $uploader = app(MediaUploader::class);
    $rule = UploadRule::resolve('t4t');

    // 首传：无 path 规则 → 根目录 hash 分片
    $first = $uploader->store(UploadedFile::fake()->image('photo.jpg', 120, 80));
    $rootKey = substr($first->hash, 0, 2).'/'.$first->hash.'.'.$first->ext;
    expect($first->path)->toBe($rootKey);

    // 同一文件经带 path 的规则再次上传（RichEditor 附件无 check 前置，直接 store）
    $second = $uploader->store(
        UploadedFile::fake()->image('photo.jpg', 120, 80),
        rule: $rule,
    );

    expect($second->is($first))->toBeTrue()
        ->and($second->path)->toBe($rootKey);

    // 磁盘上只有首传对象：目录下没有第二份文件，也没有孤儿
    expect(Storage::disk('cmf-media-local')->allFiles())->toBe([$rootKey]);
});

it('deletes the just-written object when firstOrCreate reuses a record with a different path（并发窗口兜底）', function (): void {
    actingAsTestUser();
    Storage::fake('cmf-media-tos');

    // 已有记录：无 path 规则时首传建档
    $first = app(MediaUploader::class)->store(UploadedFile::fake()->image('photo.jpg', 120, 80));
    $rootKey = $first->path;
    $richKey = 't4tstudio/'.substr($first->hash, 0, 2).'/'.$first->hash.'.'.$first->ext;

    // 并发窗口：对象已按新 path 写入（如 check 之后、建档之前另一路已完成上传）
    Storage::disk('cmf-media-tos')->put($richKey, 'just-written');
    expect(Storage::disk('cmf-media-tos')->exists($richKey))->toBeTrue();

    $media = (new MediaUploader)->firstOrCreate(
        'tos',
        $richKey,
        ['hash' => $first->hash, 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size' => $first->size],
        $first->ext,
        null,
    );

    // 复用旧记录，本次多写的对象被删除而非遗留成孤儿
    expect($media->is($first))->toBeTrue()
        ->and($media->path)->toBe($rootKey)
        ->and(Storage::disk('cmf-media-tos')->exists($richKey))->toBeFalse()
        ->and(Storage::disk('cmf-media-tos')->exists($rootKey))->toBeTrue();
});
