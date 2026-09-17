<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Signers;

use Quansitech\Cmf\Media\Contracts\DirectUploadSigner;

/**
 * TOS（火山引擎对象存储）签名器：S3 兼容 SigV4 预签名（query string 签名），
 * 限定单 key、短有效期，仅允许 PUT 指定对象。
 *
 * 注意：endpoint 必须用 TOS 的 S3 兼容域名（tos-s3-{region}.volces.com）。
 * 原生域名（tos-{region}.volces.com）只认 TOS4-HMAC-SHA256 签名，
 * 与本签名器及磁盘适配器（flysystem-aws-s3-v3，同样走 S3 协议）不匹配，会 403 AccessDenied。
 */
class TosSigner implements DirectUploadSigner
{
    public function signUpload(string $key, string $mime, int $size, array $config, array $options = []): array
    {
        $expires = (int) config('cmf-media.sign_expires', 600);

        // Content-Disposition / Cache-Control 作为未签名请求头随 PUT 写入对象元数据
        // （SignedHeaders 仅 host，SigV4 允许额外的未签名头；TOS 已实测写入生效）
        $headers = array_filter([
            'Content-Type' => $mime,
            'Content-Disposition' => $options['disposition'] ?? null,
            'Cache-Control' => $options['cache_control'] ?? null,
        ]);

        return [
            'method' => 'PUT',
            'upload_url' => $this->signUrl('PUT', $key, $config, $expires),
            'fields' => [],
            'headers' => $headers,
            'expires' => $expires,
        ];
    }

    public function signUrl(string $method, string $key, array $config, ?int $expires = null): string
    {
        $expires ??= (int) config('cmf-media.sign_expires', 600);

        /** @var string $accessKey */
        $accessKey = $config['key'] ?? '';
        /** @var string $secretKey */
        $secretKey = $config['secret'] ?? '';
        /** @var string $region */
        $region = $config['region'] ?? 'cn-beijing';
        /** @var string $bucket */
        $bucket = $config['bucket'] ?? '';
        /** @var string $endpoint */
        $endpoint = $config['endpoint'] ?? "tos-s3-{$region}.volces.com";

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $scope = "{$dateStamp}/{$region}/s3/aws4_request";

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $accessKey.'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);

        $canonicalQuery = implode('&', array_map(
            fn (string $k, string $v): string => $this->encode($k).'='.$this->encode($v),
            array_keys($query),
            $query,
        ));

        $host = "{$bucket}.{$endpoint}";
        $canonicalUri = '/'.implode('/', array_map($this->encode(...), explode('/', $key)));

        $canonicalRequest = strtoupper($method)."\n"
            .$canonicalUri."\n"
            .$canonicalQuery."\n"
            ."host:{$host}\n\n"
            ."host\n"
            .'UNSIGNED-PAYLOAD';

        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonicalRequest);

        $signingKey = $this->deriveSigningKey($secretKey, $dateStamp, $region);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "https://{$host}{$canonicalUri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    protected function deriveSigningKey(string $secret, string $dateStamp, string $region): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * RFC 3986 编码（rawurlencode）。
     */
    protected function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
