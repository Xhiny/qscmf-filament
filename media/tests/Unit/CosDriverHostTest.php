<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Signers\CosSigner;

/**
 * COS 三入口 host 一致性（回归：2024 双重 appid 缺陷）。
 *
 * flysystem-cos 的 host 模板为 <bucket>-<app_id>：provider 曾把含 appid 的
 * 全名（example-1250000000）当 bucket 传入，实际请求打向
 * example-1250000000-1250000000.cos…（桶不存在），且 404 被适配器吞成
 * "对象不存在"的静默假阴性——秒传查重恒 miss、删除无效、url() 坏域名。
 *
 * 判定标准：服务端 Filesystem 适配器与直传 CosSigner 必须打向同一 host。
 */
$cosConfig = [
    'bucket' => 'example-1250000000',
    'region' => 'ap-guangzhou',
    'secret_id' => 'AKIDtesttesttest',
    'secret_key' => 'testsecretkey',
];
$expectedHost = 'example-1250000000.cos.ap-guangzhou.myqcloud.com';

function cosAdapterOf(object $disk): object
{
    $property = new ReflectionProperty($disk, 'adapter');
    $property->setAccessible(true);

    return $property->getValue($disk);
}

function cosClientDomainOf(object $adapter): string
{
    // qcloud Client::$domain 为模板拼接（或显式传入）后的最终 host
    $client = $adapter->getObjectClient();
    $property = new ReflectionProperty($client, 'domain');
    $property->setAccessible(true);

    return (string) $property->getValue($client);
}

it('server-side adapter targets the full bucket host（bucket 裸名 + 显式 domain 兜底）', function () use ($cosConfig, $expectedHost): void {
    $disk = Storage::build(['driver' => 'cmf-media-cos', ...$cosConfig]);
    $domain = cosClientDomainOf(cosAdapterOf($disk));

    expect($domain)->toBe($expectedHost);
});

it('direct-upload signer targets the same host（直传与服务端同域）', function () use ($cosConfig, $expectedHost): void {
    $url = (new CosSigner)->signUrl('PUT', 'ab/'.str_repeat('c', 32).'.png', $cosConfig, 600);

    expect(parse_url($url, PHP_URL_HOST))->toBe($expectedHost);
});

it('never yields a double-appid host（双重 appid 回归）', function () use ($cosConfig, $expectedHost): void {
    $disk = Storage::build(['driver' => 'cmf-media-cos', ...$cosConfig]);
    $domain = cosClientDomainOf(cosAdapterOf($disk));

    // 桶名含 appid 时再拼一次 <bucket>-<app_id> 即出现双重 appid 段
    expect($domain)->not->toBe('example-1250000000-1250000000.cos.ap-guangzhou.myqcloud.com')
        ->and($domain)->toBe($expectedHost);
});

it('splits bucket name and app id without touching config semantics（配置仍传全名）', function (): void {
    // 配置约定不变：bucket 仍为全名（README「含 appid」），拆分只发生在 provider 内
    $disk = Storage::build([
        'driver' => 'cmf-media-cos',
        'bucket' => 'example-1250000000',
        'region' => 'ap-guangzhou',
        'secret_id' => 'AKIDtesttesttest',
        'secret_key' => 'testsecretkey',
    ]);
    $adapter = cosAdapterOf($disk);
    $config = (new ReflectionProperty($adapter, 'config'));
    $config->setAccessible(true);

    expect($config->getValue($adapter)['bucket'])->toBe('example')
        ->and($config->getValue($adapter)['app_id'])->toBe('1250000000');
});
