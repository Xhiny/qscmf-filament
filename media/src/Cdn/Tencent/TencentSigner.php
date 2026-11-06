<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn\Tencent;

/**
 * 腾讯云 CDN 鉴权签名器基类（TypeA/B/C/D）：密钥持有与时间戳量化。
 *
 * 各类型的 md5 拼接顺序与时间戳进制互不相同、不可互换，实现类头注释
 * 钉官方文档：https://cloud.tencent.com/document/product/228/41622
 */
abstract class TencentSigner implements \Quansitech\Cmf\Media\Cdn\CdnAuthSigner
{
    public function __construct(
        protected readonly string $key,
        protected readonly int $quantizeSeconds = 0,
    ) {}

    /**
     * 时间戳量化：对齐到 quantize_seconds 的整倍数桶，同桶内签发的 URL
     * 完全一致（页面输出稳定、日志收敛）；0/1 = 不量化。
     */
    final protected function timestamp(?int $at): int
    {
        $at ??= time();

        return $this->quantizeSeconds > 1
            ? intdiv($at, $this->quantizeSeconds) * $this->quantizeSeconds
            : $at;
    }

}
