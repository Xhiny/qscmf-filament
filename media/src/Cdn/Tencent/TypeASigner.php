<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn\Tencent;

/**
 * 腾讯云 CDN TypeA 鉴权。
 *
 * 官方文档：https://cloud.tencent.com/document/product/228/41623
 *
 *   URL：http://DomainName/FileName?sign=timestamp-rand-uid-md5hash
 *   md5：md5sum(uri-timestamp-rand-uid-pkey)，连字符拼接，密钥在最后
 *   timestamp：十进制 Unix 秒
 *
 * rand 建议配置固定值（量化语境下随机值会让 URL 每次不同）；uid 默认 0。
 */
final class TypeASigner extends TencentSigner
{
    public function __construct(
        string $key,
        protected readonly string $rand,
        protected readonly string $uid,
        int $quantizeSeconds = 0,
    ) {
        parent::__construct($key, $quantizeSeconds);
    }

    public function sign(string $baseUrl, string $uri, ?int $at = null): string
    {
        $timestamp = (string) $this->timestamp($at);
        $md5 = md5($uri.'-'.$timestamp.'-'.$this->rand.'-'.$this->uid.'-'.$this->key);

        return $baseUrl.$uri.'?sign='.$timestamp.'-'.$this->rand.'-'.$this->uid.'-'.$md5;
    }
}
