<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Cdn\CdnSignerFactory;

/**
 * 四类型签名器公式守护：期望值全部复算自腾讯云官方文档示例
 * （product/228/41623-41625、41871），拼接顺序或进制回归即红。
 *
 * 官方示例公共参数：pkey = dimtm5evg50ijsx2hvuwyfoiu65，uri = /test.jpg，
 * 签发时刻 2020-02-27 16:10:32（UTC+8）= Unix 1582791032。
 */
const KEY = 'dimtm5evg50ijsx2hvuwyfoiu65';

const AT = 1582791032;

const BASE = 'https://cdn.example.com';

it('type_a matches the official sample', function (): void {
    $signer = CdnSignerFactory::fromConfig(['auth_type' => 'type_a', 'primary_key' => KEY, 'rand' => 'im1acp76sx9sdqe601v', 'uid' => '0']);

    expect($signer)->not->toBeNull()
        ->and($signer->sign(BASE, '/test.jpg', AT))
        ->toBe(BASE.'/test.jpg?sign=1582791032-im1acp76sx9sdqe601v-0-3fbb88382c9356b6faaf9d68c7b2ae3a');
});

it('type_b matches the official sample', function (): void {
    $signer = CdnSignerFactory::fromConfig(['auth_type' => 'type_b', 'primary_key' => KEY]);

    expect($signer)->not->toBeNull()
        ->and($signer->sign(BASE, '/test.jpg', AT))
        ->toBe(BASE.'/202002271610/2e03a07cfa55a47768226d3e5ea82a8d/test.jpg');
});

it('type_c matches the official sample', function (): void {
    $signer = CdnSignerFactory::fromConfig(['auth_type' => 'type_c', 'primary_key' => KEY]);

    expect($signer)->not->toBeNull()
        ->and($signer->sign(BASE, '/test.jpg', AT))
        ->toBe(BASE.'/7913fc0c5c9e92dd3633b7895152bbb2/5e577978/test.jpg');
});

it('type_d matches the official sample', function (): void {
    $signer = CdnSignerFactory::fromConfig(['auth_type' => 'type_d', 'primary_key' => KEY]);

    expect($signer)->not->toBeNull()
        ->and($signer->sign(BASE, '/test.jpg', AT))
        ->toBe(BASE.'/test.jpg?sign=900a5049aa8ac1ab144527d9c2be4cea&t=1582791032');
});

it('type_d honours custom param names', function (): void {
    $signer = CdnSignerFactory::fromConfig([
        'auth_type' => 'type_d', 'primary_key' => KEY,
        'sign_param' => 's', 'timestamp_param' => 'ts',
    ]);

    expect($signer->sign(BASE, '/test.jpg', AT))
        ->toBe(BASE.'/test.jpg?s=900a5049aa8ac1ab144527d9c2be4cea&ts=1582791032');
});

it('quantizes the timestamp into stable buckets', function (): void {
    $signer = CdnSignerFactory::fromConfig(['auth_type' => 'type_d', 'primary_key' => KEY, 'quantize_seconds' => 300]);

    // 从桶边界时刻（1582791000 = 300 的整倍数）出发：同桶任意时刻 URL 一致，跨桶变化
    $inBucket = $signer->sign(BASE, '/test.jpg', 1582791000);
    expect($signer->sign(BASE, '/test.jpg', 1582791000 + 299))->toBe($inBucket)
        ->and($signer->sign(BASE, '/test.jpg', 1582791000 + 300))->not->toBe($inBucket);
});

it('returns null without auth_type or key（未配置 = 不签名）', function (): void {
    expect(CdnSignerFactory::fromConfig([]))->toBeNull()
        ->and(CdnSignerFactory::fromConfig(['url' => 'https://cdn.example.com']))->toBeNull()
        ->and(CdnSignerFactory::fromConfig(['primary_key' => KEY]))->toBeNull();
});

it('throws when auth_type is set without primary_key（半配置守卫）', function (): void {
    CdnSignerFactory::fromConfig(['auth_type' => 'type_d']);
})->throws(InvalidArgumentException::class, '缺少 cdn.primary_key');

it('rejects unknown auth types', function (): void {
    CdnSignerFactory::fromConfig(['auth_type' => 'type_x', 'primary_key' => KEY]);
})->throws(InvalidArgumentException::class, '未知 CDN 鉴权类型');
