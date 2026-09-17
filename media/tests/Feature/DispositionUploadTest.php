<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

/*
 * disposition 纳入去重维度（TOS 实测 URL 覆盖不可行后的 A 方案）：
 * 同一内容哈希按访问行为（inline / attachment / 无）各存一个对象，
 * 对象 key 形如 {hash前2位}/{hash}[.{disposition}].{ext}，访问行为头在上传时
 * 写入对象元数据（check / sign / callback 全链路一致）。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'doc-preview' => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
        'doc-download' => ['mimes' => ['application/pdf'], 'disposition' => 'attachment'],
    ]]);
});

it('object key embeds disposition while keeping the legacy format without one', function (): void {
    $hash = str_repeat('a', 32);

    expect(Media::objectKey($hash, 'pdf'))->toBe('aa/'.$hash.'.pdf')
        ->and(Media::objectKey($hash, 'pdf', 'inline'))->toBe('aa/'.$hash.'.inline.pdf')
        ->and(Media::objectKey($hash, 'pdf', 'attachment'))->toBe('aa/'.$hash.'.attachment.pdf')
        ->and(Media::objectKey($hash, '', 'inline'))->toBe('aa/'.$hash.'.inline');
});

it('sign derives the object key and metadata headers from the rule disposition', function (): void {
    actingAsTestUser();

    $hash = str_repeat('b', 32);

    $inline = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => '合同.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
        'rule' => 'doc-preview',
    ])->assertOk();

    expect($inline->json('path'))->toBe('bb/'.$hash.'.inline.pdf')
        ->and($inline->json('headers.Content-Type'))->toBe('application/pdf')
        ->and($inline->json('headers.Content-Disposition'))->toBe('inline')
        ->and($inline->json('headers.Cache-Control'))->toBe('max-age=86400, public');

    $download = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => '合同.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
        'rule' => 'doc-download',
    ])->assertOk();

    expect($download->json('path'))->toBe('bb/'.$hash.'.attachment.pdf');
    $disposition = $download->json('headers.Content-Disposition');
    expect($disposition)->toStartWith('attachment; filename="')
        ->and($disposition)->toContain("filename*=UTF-8''".rawurlencode('合同.pdf'));

    // 未声明规则：保持原 key 格式，无元数据头（存量行为不变）
    $plain = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => '合同.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
    ])->assertOk();

    expect($plain->json('path'))->toBe('bb/'.$hash.'.pdf')
        ->and($plain->json('headers'))->toBe(['Content-Type' => 'application/pdf']);
});

it('check dedupes by hash + disposition （同一内容不同入口各自秒传）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('c', 32);
    $inlineMedia = createMedia([
        'hash' => $hash,
        'disposition' => 'inline',
        'path' => Media::objectKey($hash, 'pdf', 'inline'),
        'mime' => 'application/pdf',
        'ext' => 'pdf',
    ]);

    // 同 disposition 入口命中
    $this->postJson(route('cmf-media.check'), [
        'hash' => $hash,
        'size' => 1024,
        'mime' => 'application/pdf',
        'rule' => 'doc-preview',
    ])->assertOk()->assertJsonPath('media.id', $inlineMedia->id);

    // 无 disposition 入口不命中（对象不同，需另行上传）
    $this->postJson(route('cmf-media.check'), [
        'hash' => $hash,
        'size' => 1024,
        'mime' => 'application/pdf',
    ])->assertNotFound();

    // attachment 入口也不命中
    $this->postJson(route('cmf-media.check'), [
        'hash' => $hash,
        'size' => 1024,
        'mime' => 'application/pdf',
        'rule' => 'doc-download',
    ])->assertNotFound();
});

it('callback creates separate records per disposition for the same hash', function (): void {
    Storage::fake('cmf-media-tos');
    actingAsTestUser();

    $hash = str_repeat('d', 32);
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    foreach (['doc-preview' => 'inline', 'doc-download' => 'attachment', null => ''] as $rule => $disposition) {
        $path = Media::objectKey($hash, 'pdf', $disposition);
        Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));

        $this->postJson(route('cmf-media.callback'), array_filter([
            'hash' => $hash,
            'path' => $path,
            'name' => 'doc.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'rule' => $rule,
        ]))->assertOk()->assertJsonPath('media.path', $path);
    }

    // 同一内容三个访问行为各一条记录
    expect(Media::where('hash', $hash)->count())->toBe(3)
        ->and(Media::where('hash', $hash)->pluck('disposition')->sort()->values()->all())
        ->toBe(['', 'attachment', 'inline']);

    // 重复回调命中各自记录，不新增
    $path = Media::objectKey($hash, 'pdf', 'inline');
    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 100,
        'rule' => 'doc-preview',
    ])->assertOk();
    expect(Media::where('hash', $hash)->count())->toBe(3);
});

it('callback rejects a path whose disposition dimension does not match the rule', function (): void {
    actingAsTestUser();

    $hash = str_repeat('e', 32);

    // 声明 doc-preview（inline），却上报无 disposition 的 key
    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => Media::objectKey($hash, 'pdf'),
        'name' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 100,
        'rule' => 'doc-preview',
    ])->assertUnprocessable()->assertJsonValidationErrors('path');
});
