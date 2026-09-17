<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

/*
 * 按入口规则的文件访问行为（XSDA-7 验收 3）：
 * 访问行为（缓存时长 / 预览或下载 / 下载文件名）在上传时写入对象元数据，
 * disposition 纳入去重维度（同一内容按入口各存一个对象）；
 * local 驱动走 cmf-media.file 路由由服务端输出对应响应头。
 * 云厂商 response-* URL 覆盖参数实测不可靠（TOS 匿名 GET 返回 400），不使用。
 */

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'doc-preview' => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
        'doc-download' => ['mimes' => ['application/pdf'], 'disposition' => 'attachment'],
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    ]]);
});

it('returns the plain public url for cloud media （访问行为在对象元数据上）', function (): void {
    $media = createMedia([
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'path' => 'ab/'.str_repeat('a', 32).'.inline.pdf',
        'disposition' => 'inline',
        'original_name' => '合同 扫描件.pdf',
    ]);

    $publicUrl = $media->url();

    // 云驱动不再拼 response-* 参数（TOS 实测匿名 GET 带参数返回 400）
    expect($media->urlForEntry('doc-preview'))->toBe($publicUrl)
        ->and($media->urlForEntry('doc-download'))->toBe($publicUrl)
        ->and($publicUrl)->not->toContain('response-');
});

it('keeps url unchanged without rule or without access behavior', function (): void {
    $media = createMedia();

    expect($media->urlForEntry(null))->toBe($media->url())
        ->and($media->urlForEntry('img-10'))->toBe($media->url())
        ->and($media->url())->not->toContain('response-');
});

it('throws for unknown rule names when generating urls', function (): void {
    createMedia()->urlForEntry('ghost');
})->throws(InvalidArgumentException::class, '未知的媒体上传规则');

it('routes local media with access behavior through the file endpoint', function (): void {
    $media = createMedia(['disk' => 'local', 'mime' => 'application/pdf', 'ext' => 'pdf']);

    expect($media->urlForEntry('doc-download'))
        ->toBe(route('cmf-media.file', ['media' => $media->id, 'rule' => 'doc-download']));

    // 无访问行为的规则仍用公开 URL
    expect($media->urlForEntry('img-10'))->toBe($media->url());
});

it('serves local files with the rule headers', function (): void {
    Storage::fake('cmf-media-local');

    $media = createMedia([
        'disk' => 'local',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'original_name' => '资质证明.pdf',
    ]);
    Storage::disk('cmf-media-local')->put($media->path, 'pdf-content');

    // 强制下载入口：attachment + 原始文件名
    $response = $this->get(route('cmf-media.file', ['media' => $media->id, 'rule' => 'doc-download']));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toStartWith('attachment; filename="')
        ->toContain("filename*=UTF-8''".rawurlencode('资质证明.pdf'));
    expect($response->streamedContent())->toBe('pdf-content');

    // 在线预览入口：inline + 缓存时长
    $preview = $this->get(route('cmf-media.file', ['media' => $media->id, 'rule' => 'doc-preview']));
    $preview->assertOk();
    expect($preview->headers->get('Content-Disposition'))->toBe('inline')
        ->and($preview->headers->get('Cache-Control'))->toContain('max-age=86400');
});

it('serves local files with default disposition when no rule given', function (): void {
    Storage::fake('cmf-media-local');

    $media = createMedia(['disk' => 'local']);
    Storage::disk('cmf-media-local')->put($media->path, 'img');

    $response = $this->get(route('cmf-media.file', ['media' => $media->id]));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('redirects cloud media on the file endpoint to the plain public url', function (): void {
    $media = createMedia(['mime' => 'application/pdf', 'ext' => 'pdf', 'original_name' => 'doc.pdf']);

    $response = $this->get(route('cmf-media.file', ['media' => $media->id, 'rule' => 'doc-download']));

    $response->assertRedirect();
    // 云驱动访问行为在对象元数据上，重定向目标不拼 response-* 参数
    expect($response->headers->get('Location'))->toBe($media->url());
});

it('returns 404 for missing local object or missing media', function (): void {
    Storage::fake('cmf-media-local');

    $media = createMedia(['disk' => 'local']);

    $this->get(route('cmf-media.file', ['media' => $media->id]))->assertNotFound();
    $this->get(route('cmf-media.file', ['media' => 999999]))->assertNotFound();
});
