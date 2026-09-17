<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

/*
 * 按入口上传规则的服务端强制校验（XSDA-7）：
 * check / sign / upload / callback 全链路按入口规则执行，
 * 绕开前端直接调接口同样被拒；规则只能比全局白名单更严。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
        'img-20' => ['mimes' => ['image/*'], 'max_size' => 20 * 1024 * 1024],
        'bad-mime' => ['mimes' => ['application/x-msdownload']], // 超出全局白名单
    ]]);
});

it('enforces per-entry size limits on sign: entry A rejects what entry B accepts （验收 1）', function (): void {
    actingAsTestUser();

    $payload = [
        'hash' => str_repeat('a', 32),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 15 * 1024 * 1024, // 同一张 15MB 图片
    ];

    // 入口 A「图片 ≤10MB」拒
    $this->postJson(route('cmf-media.sign'), [...$payload, 'rule' => 'img-10'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('size');

    // 入口 B「图片 ≤20MB」过
    $this->postJson(route('cmf-media.sign'), [...$payload, 'rule' => 'img-20'])
        ->assertOk();
});

it('rejects a zip on the sign api against an image-only rule even bypassing the frontend （验收 2）', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('b', 32),
        'name' => 'archive.zip',
        'mime' => 'application/zip',
        'size' => 1024,
        'rule' => 'img-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('mime');
});

it('rejects unknown rule names on sign with 422', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('c', 32),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 1024,
        'rule' => 'ghost',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('rule');
});

it('rejects rules configured wider than the global whitelist （只能更严）', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('d', 32),
        'name' => 'evil.exe',
        'mime' => 'application/x-msdownload',
        'size' => 1024,
        'rule' => 'bad-mime',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('rule');
});

it('keeps sign without rule on the global limits exactly as before', function (): void {
    actingAsTestUser();

    // 未声明规则：15MB 图片按全局 500MB 放行（存量行为不变）
    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('e', 32),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 15 * 1024 * 1024,
    ])->assertOk();
});

it('enforces the rule on check so instant-hit cannot bypass it', function (): void {
    actingAsTestUser();

    // 15MB 图片已在库中（比如经 img-20 入口传过）
    $media = createMedia(['size' => 15 * 1024 * 1024]);

    // img-10 入口秒传同样被拒
    $this->postJson(route('cmf-media.check'), [
        'hash' => $media->hash,
        'size' => 15 * 1024 * 1024,
        'mime' => 'image/jpeg',
        'rule' => 'img-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');

    // img-20 入口正常秒传
    $this->postJson(route('cmf-media.check'), [
        'hash' => $media->hash,
        'size' => 15 * 1024 * 1024,
        'mime' => 'image/jpeg',
        'rule' => 'img-20',
    ])->assertOk()
        ->assertJsonPath('media.id', $media->id);
});

it('keeps check without rule unvalidated as before （存量行为不变）', function (): void {
    actingAsTestUser();

    // check 历史上不校验类型/大小：未声明规则时维持原样
    $this->postJson(route('cmf-media.check'), [
        'hash' => str_repeat('f', 32),
        'size' => 600 * 1024 * 1024, // 超过全局上限，但无规则时 check 不拦
        'mime' => 'application/x-msdownload',
    ])->assertNotFound();
});

it('enforces the rule on callback', function (): void {
    Storage::fake('cmf-media-tos');
    actingAsTestUser();

    $hash = str_repeat('1', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, 'x');
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 15 * 1024 * 1024,
        'rule' => 'img-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

it('enforces the rule on the local relay upload endpoint （直传与中转同一套规则，验收 4）', function (): void {
    config(['cmf-media.default' => 'local']);
    Storage::fake('cmf-media-local');
    $this->withHeaders(['Accept' => 'application/json']);
    actingAsTestUser();

    // 15MB 图片：img-10 拒
    $big = UploadedFile::fake()->create('photo.jpg', 15 * 1024, 'image/jpeg');
    $this->post(route('cmf-media.upload'), [
        'file' => $big,
        'hash' => md5($big->get()),
        'name' => 'photo.jpg',
        'rule' => 'img-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');

    // zip：服务端探测 MIME 后被图片规则拒
    $zip = UploadedFile::fake()->create('archive.zip', 10, 'application/zip');
    $this->post(route('cmf-media.upload'), [
        'file' => $zip,
        'hash' => md5($zip->get()),
        'name' => 'archive.zip',
        'rule' => 'img-10',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('mime');

    // 合规图片：正常建档
    $ok = UploadedFile::fake()->image('photo.jpg');
    $this->post(route('cmf-media.upload'), [
        'file' => $ok,
        'hash' => md5($ok->get()),
        'name' => 'photo.jpg',
        'rule' => 'img-10',
    ])->assertOk();
});

it('sign for local driver carries the rule through to the upload fields （验收 4）', function (): void {
    config(['cmf-media.default' => 'local']);
    actingAsTestUser();

    $response = $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('2', 32),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
        'rule' => 'img-10',
    ])->assertOk();

    expect($response->json('fields.rule'))->toBe('img-10');
});
