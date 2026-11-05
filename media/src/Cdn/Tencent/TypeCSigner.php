<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn\Tencent;

/**
 * 腾讯云 CDN TypeC 鉴权（签名与时间戳在路径段，十六进制）。
 *
 * 官方文档：https://cloud.tencent.com/document/product/228/41624
 *
 *   URL：http://DomainName/md5hash/timestamp/FileName
 *   md5：md5sum(pkey + uri + timestamp)，无分隔符，密钥在最前
 *   timestamp：十六进制 Unix 秒（如 5e577978）
 *
 * CDN 缓存自动忽略路径中的鉴权段、回源时剥除，不影响缓存命中。
 */
final class TypeCSigner extends TencentSigner
{
    public function sign(string $baseUrl, string $uri, ?int $at = null): string
    {
        $timestamp = dechex($this->timestamp($at));
        $md5 = md5($this->key.$uri.$timestamp);

        return $baseUrl.'/'.$md5.'/'.$timestamp.$uri;
    }
}
