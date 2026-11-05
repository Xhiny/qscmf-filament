<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn\Tencent;

/**
 * 腾讯云 CDN TypeB 鉴权（签名与时间戳在路径段）。
 *
 * 官方文档：https://cloud.tencent.com/document/product/228/41871
 *
 *   URL：http://DomainName/timestamp/md5hash/FileName
 *   md5：md5sum(pkey + timestamp + uri)，无分隔符，密钥在最前
 *   timestamp：北京时间 YYYYMMDDHHmm（示例 202002271610），分钟粒度
 *
 * CDN 缓存自动忽略路径中的鉴权段、回源时剥除，不影响缓存命中。
 */
final class TypeBSigner extends TencentSigner
{
    public function sign(string $baseUrl, string $uri, ?int $at = null): string
    {
        $timestamp = (new \DateTimeImmutable('@'.$this->timestamp($at)))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('YmdHi');

        $md5 = md5($this->key.$timestamp.$uri);

        return $baseUrl.'/'.$timestamp.'/'.$md5.$uri;
    }
}
