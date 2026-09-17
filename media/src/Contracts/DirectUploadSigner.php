<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Contracts;

/**
 * 直传签名器：为浏览器直传生成限定单 key、短有效期的上传凭证，
 * 并为服务端 headObject 校验生成签名请求。
 */
interface DirectUploadSigner
{
    /**
     * 生成浏览器直传凭证。
     *
     * $options 承载写入对象元数据的访问行为头（访问时云厂商按对象元数据返回，
     * URL 期 response-* 覆盖实测不可靠）：
     * - disposition：Content-Disposition 完整头值（attachment 已带 RFC 5987 文件名）；
     * - cache_control：Cache-Control 头值（如 "max-age=86400, public"）。
     *
     * @param  array<string, mixed>  $config  cmf-media.disks.{driver} 配置
     * @param  array{disposition?: string|null, cache_control?: string|null}  $options
     * @return array{method: string, upload_url: string, fields: array<string, string>, headers: array<string, string>, expires: int}
     */
    public function signUpload(string $key, string $mime, int $size, array $config, array $options = []): array;

    /**
     * 生成指定 HTTP 方法的签名 URL（服务端 headObject 校验用）。
     *
     * @param  array<string, mixed>  $config
     */
    public function signUrl(string $method, string $key, array $config, ?int $expires = null): string;
}
