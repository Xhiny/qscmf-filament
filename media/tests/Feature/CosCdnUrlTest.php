<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
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

it('strips cdn path_prefix and signs the stripped uri（子目录关联）', function () use ($baseCreds): void {
    // 前缀故意带不规范首尾斜杠：构造期归一后仍应正确剥离
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com/',
        'auth_type' => 'type_a',
        'primary_key' => 'Kpath',
        'rand' => 'fixedrand',
        'path_prefix' => '/t4tstudio/',
    ]]);

    $url = $disk->url('t4tstudio/image/ab/x.jpg');

    expect(parse_url($url, PHP_URL_HOST))->toBe('cdn.example.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/image/ab/x.jpg')
        ->and($url)->not->toContain('t4tstudio');

    // TypeA 边缘复算口径：md5(剥后uri-ts-rand-uid-key)，签名 uri 必须用剥后 path
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    [$ts, $rand, $uid, $hash] = explode('-', (string) $query['sign']);
    expect($hash)->toBe(md5(sprintf('/image/ab/x.jpg-%s-%s-%s-Kpath', $ts, $rand, $uid)))
        ->and($rand)->toBe('fixedrand');
});

it('rejects paths outside the cdn path_prefix（不在关联目录下抛）', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'auth_type' => 'type_a',
        'primary_key' => 'Kpath',
        'path_prefix' => 't4tstudio',
    ]]);

    expect(fn () => $disk->url('t4tstudio2/x.jpg'))->toThrow(InvalidArgumentException::class, '不在 CDN 关联目录')
        ->and(fn () => $disk->url('outside/x.jpg'))->toThrow(InvalidArgumentException::class);
});

it('keeps temporaryUrl aligned with the stripped getUrl（同口径）', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'auth_type' => 'type_a',
        'primary_key' => 'Kpath',
        'path_prefix' => 't4tstudio',
    ]]);

    expect($disk->temporaryUrl('t4tstudio/image/ab/x.jpg', 60))->toBe($disk->url('t4tstudio/image/ab/x.jpg'));
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

it('strips cdn path_prefix without a signer（仅加速：只剥拼接）', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'path_prefix' => 't4tstudio',
    ]]);

    expect($disk->url('t4tstudio/image/ab/x.jpg'))->toBe('https://cdn.example.com/image/ab/x.jpg')
        ->and(fn () => $disk->url('outside/x.jpg'))->toThrow(InvalidArgumentException::class);
});

it('keeps the native q-sign over the full key when auth is off（仅加速：temporaryUrl 不剥）', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'path_prefix' => 't4tstudio',
    ]]);

    $url = $disk->temporaryUrl('t4tstudio/image/ab/x.jpg', 60);

    expect(parse_url($url, PHP_URL_HOST))->toBe('example-1250000000.cos.ap-guangzhou.myqcloud.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/t4tstudio/image/ab/x.jpg')
        ->and($url)->toContain('sign=');
});

it('normalizes whitespace around the cdn path_prefix（env 带空格仍能命中）', function () use ($baseCreds): void {
    $disk = Storage::build([...$baseCreds, 'cdn' => [
        'url' => 'https://cdn.example.com',
        'path_prefix' => " t4tstudio\t",
    ]]);

    expect($disk->url('t4tstudio/image/ab/x.jpg'))->toBe('https://cdn.example.com/image/ab/x.jpg');
});

it('returns null from the model and logs when the path falls outside the prefix（模型层留痕）', function (): void {
    Log::spy();

    config()->set('filesystems.disks.cmf-media-cos', [
        'driver' => 'cmf-media-cos',
        'bucket' => 'example-1250000000',
        'region' => 'ap-guangzhou',
        'secret_id' => 'AKIDtesttesttest',
        'secret_key' => 'testsecretkey',
        'cdn' => [
            'url' => 'https://cdn.example.com',
            'auth_type' => 'type_a',
            'primary_key' => 'Kpath',
            'path_prefix' => 't4tstudio',
        ],
    ]);

    $outside = createMedia(['disk' => 'cos', 'path' => 'outside/ab/'.str_repeat('c', 32).'.jpg']);
    $inside = createMedia(['disk' => 'cos', 'path' => 't4tstudio/ab/'.str_repeat('d', 32).'.jpg']);

    expect($outside->url())->toBeNull()
        ->and($inside->url())->toContain('https://cdn.example.com/ab/');

    Log::shouldHaveReceived('warning')->once();
});

it('logs once per disk when a registered disk fails to resolve（半配置解析期）', function (): void {
    Log::spy();

    config()->set('filesystems.disks.cmf-media-ghost-cos', [
        'driver' => 'cmf-media-cos',
        'bucket' => 'example-1250000000',
        'region' => 'ap-guangzhou',
        'secret_id' => 'AKIDtesttesttest',
        'secret_key' => 'testsecretkey',
        'cdn' => ['url' => null, 'auth_type' => 'type_a', 'primary_key' => 'Kpath'],
    ]);

    $first = createMedia(['disk' => 'ghost-cos', 'path' => 'gh/'.str_repeat('a', 32).'.jpg']);
    $second = createMedia(['disk' => 'ghost-cos', 'path' => 'gh/'.str_repeat('b', 32).'.jpg']);
    $unregistered = createMedia(['disk' => 'ghost', 'path' => 'gh/'.str_repeat('c', 32).'.jpg']);

    expect($first->url())->toBeNull()
        ->and($second->url())->toBeNull()
        ->and($unregistered->url())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});
