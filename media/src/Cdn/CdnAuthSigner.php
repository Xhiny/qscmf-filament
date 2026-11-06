<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Cdn;

/**
 * CDN 边缘鉴权签名器。
 *
 * 私有桶 + CDN 加速域名的访问出口：COS 原生 q-sign 签名 URL 打 CDN 域名
 * 会被边缘直接 403（两套验证体系互斥），必须按 CDN 厂商的鉴权算法签发。
 * 有效期由 CDN 控制台「鉴权有效时间」全局决定，与逐 URL 指定无关。
 *
 * 本目录不绑定具体存储厂商：后续 TOS / OSS 的 CDN 鉴权签名器按同一接口
 * 扩展即可（接入 = 新增签名器类 + 对应 extend 闭包接线 + disks.<驱动>.cdn
 * 配置段）。
 */
interface CdnAuthSigner
{
    /**
     * 生成带边缘鉴权签名的访问 URL。
     *
     * @param  string  $baseUrl  CDN 域名（无尾斜杠），如 https://cdn.example.com
     * @param  string  $uri  资源路径，以 / 开头，与最终访问 URL 的 path 一致
     *                       （CDN 关联子目录时已剥掉回源前缀，此时不等于对象 key）
     * @param  int|null  $at  签发时间戳（Unix 秒）；null = 当前时刻。测试注入点
     */
    public function sign(string $baseUrl, string $uri, ?int $at = null): string;
}
