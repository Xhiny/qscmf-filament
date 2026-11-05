<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn;

use Quansitech\Cmf\Media\Cdn\Tencent\TencentSigner;
use Quansitech\Cmf\Media\Cdn\Tencent\TypeASigner;
use Quansitech\Cmf\Media\Cdn\Tencent\TypeBSigner;
use Quansitech\Cmf\Media\Cdn\Tencent\TypeCSigner;
use Quansitech\Cmf\Media\Cdn\Tencent\TypeDSigner;

/**
 * CDN 鉴权签名器统一工厂：按 auth_type 分发到厂商实现。
 *
 * 接入新厂商 = 该厂商子命名空间实现签名器 + 此处 match 一行 +
 * disks.<驱动>.cdn 配置段。auth_type 值当前为腾讯 TypeA-D；其他厂商
 * （TOS auth_key / 阿里 auth_key 等）使用各自命名空间的取值，避免
 * 「同名不同义」（两家 CDN 均有 A/B/C 类命名，算法并不相同）。
 */
final class CdnSignerFactory
{
    /**
     * @param  array{auth_type?: mixed, primary_key?: mixed, quantize_seconds?: mixed, rand?: mixed, uid?: mixed, sign_param?: mixed, timestamp_param?: mixed}  $cdn
     */
    public static function fromConfig(array $cdn): ?CdnAuthSigner
    {
        $type = strtolower(trim((string) ($cdn['auth_type'] ?? '')));
        $key = trim((string) ($cdn['primary_key'] ?? ''));

        if ($type === '') {
            return null;
        }

        if ($key === '') {
            throw new \InvalidArgumentException('已配置 disks.cos.cdn.auth_type 但缺少 cdn.primary_key（控制台主密钥）——签名无法生成，裸 CDN URL 在已开启边缘鉴权的域名上必 403');
        }

        $quantize = max(0, (int) ($cdn['quantize_seconds'] ?? 0));

        return match ($type) {
            'type_a' => new TypeASigner($key, (string) ($cdn['rand'] ?? ''), (string) ($cdn['uid'] ?? '0'), $quantize),
            'type_b' => new TypeBSigner($key, $quantize),
            'type_c' => new TypeCSigner($key, $quantize),
            'type_d' => new TypeDSigner($key, (string) ($cdn['sign_param'] ?? 'sign'), (string) ($cdn['timestamp_param'] ?? 't'), $quantize),
            default => throw new \InvalidArgumentException("未知 CDN 鉴权类型 [{$type}]，支持 type_a / type_b / type_c / type_d"),
        };
    }
}
