<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn\Tencent;

/**
 * 腾讯云 CDN TypeD 鉴权（query 双参数，推荐：参数名与控制台一致可配）。
 *
 * 官方文档：https://cloud.tencent.com/document/product/228/41625
 *
 *   URL：http://DomainName/FileName?sign=md5hash&t=timestamp（参数名可配）
 *   md5：md5sum(pkey + uri + timestamp)，无分隔符，密钥在最前
 *   timestamp：十进制 Unix 秒
 *
 * CDN 缓存 key 自动排除鉴权参数与时间戳参数（优先级高于「过滤参数」
 * 设置），追加缩略图等处理参数不受影响。
 */
final class TypeDSigner extends TencentSigner
{
    public function __construct(
        string $key,
        protected readonly string $signParam,
        protected readonly string $timestampParam,
        int $quantizeSeconds = 0,
    ) {
        parent::__construct($key, $quantizeSeconds);
    }

    public function sign(string $baseUrl, string $uri, ?int $at = null): string
    {
        $timestamp = (string) $this->timestamp($at);
        $md5 = md5($this->key.$uri.$timestamp);

        return $baseUrl.$uri.'?'.$this->signParam.'='.$md5.'&'.$this->timestampParam.'='.$timestamp;
    }
}
