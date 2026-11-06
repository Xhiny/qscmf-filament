<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Overtrue\Flysystem\Cos\CosAdapter;
use Quansitech\Cmf\Media\Flysystem\CosCdnAdapter;

/**
 * CDN 配置接线：cmf-media-cos 驱动的 url() 出口分流。
 *
 * - 配 cdn（url + auth_type）→ CosCdnAdapter 签名 URL；
 * - 仅配 cdn.url 未开鉴权 → 裸 CDN URL；
 * - 完全未配 → 原生 CosAdapter 行为不变（host 口径回归）。
 */
$baseCreds = [
    'driver' => 'cmf-media-cos',
    'bucket' => 'example-1250000000',
    'region' => 'ap-guangzhou',
    'secret_id' => 'AKIDtesttesttest',
    'secret_key' => 'testsecretkey',
];

it('signs the url when cdn auth is configured', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'auth_type' => 'type_d',
        'primary_key' => 'dimtm5evg50ijsx2hvuwyfoiu65',
        'quantize_seconds' => 300,
    ]]);

    $bucket = intdiv(time(), 300) * 300;

    expect($disk->getAdapter())->toBeInstanceOf(CosCdnAdapter::class)
        ->and($disk->url('ab/'.str_repeat('c', 32).'.jpg'))
        ->toBe('https://cdn.example.com/ab/'.str_repeat('c', 32).'.jpg'
            .'?sign='.md5('dimtm5evg50ijsx2hvuwyfoiu65/ab/'.str_repeat('c', 32).'.jpg'.$bucket)
            .'&t='.$bucket);
});

it('serves a bare cdn url when auth is off', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => ['url' => 'https://cdn.example.com']]);

    expect($disk->getAdapter())->toBeInstanceOf(CosCdnAdapter::class)
        ->and($disk->url('ab/x.jpg'))->toBe('https://cdn.example.com/ab/x.jpg');
});

it('keeps the native adapter untouched without cdn config（存量行为回归）', function () use ($baseCreds): void {
    $disk = Storage::build($baseCreds);

    expect($disk->getAdapter())->toBeInstanceOf(CosAdapter::class)
        ->and($disk->getAdapter())->not->toBeInstanceOf(CosCdnAdapter::class)
        // url() 仍为 COS 域名口径（domain 兜底，host 与直传一致——CosDriverHostTest 守护）
        ->and(parse_url($disk->url('ab/x.jpg'), PHP_URL_HOST))
        ->toBe('example-1250000000.cos.ap-guangzhou.myqcloud.com');
});

it('throws a clear config exception when auth_type is set without cdn.url（半配置守卫，盘使用即抛）', function () use ($baseCreds): void {
    expect(fn () => Storage::build([...$baseCreds, 'cdn' => [
        'url' => null,
        'auth_type' => 'type_a',
        'primary_key' => 'somekey',
    ]]))->toThrow(InvalidArgumentException::class, 'cdn.url');
});

it('throws a clear config exception when auth_type is set without primary_key（半配置守卫，盘使用即抛）', function () use ($baseCreds): void {
    expect(fn () => Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'auth_type' => 'type_a',
        'primary_key' => null,
    ]]))->toThrow(InvalidArgumentException::class, 'cdn.primary_key');
});

it('keeps the native adapter url() working with the real default cdn structure（真实默认结构回归）', function () use ($baseCreds): void {
    // 直接加载包默认 config 的真实 cdn 结构（env 未配 = 值全 null 的非空数组），
    // 不手抄键列——config 增删键/改默认值时测试自动跟随，永不漂移。
    // 原生 CosAdapter 把 cdn 当字符串处理（rtrim），传入数组会 TypeError。
    $defaultCdn = (require __DIR__.'/../../config/cmf-media.php')['disks']['cos']['cdn'];
    expect($defaultCdn)->toBeArray()->not->toBeEmpty();

    $disk = Storage::build([...$baseCreds, 'cdn' => $defaultCdn]);

    expect($disk->getAdapter())->toBeInstanceOf(CosAdapter::class)
        ->and(parse_url($disk->url('ab/x.jpg'), PHP_URL_HOST))
        ->toBe('example-1250000000.cos.ap-guangzhou.myqcloud.com');
});
