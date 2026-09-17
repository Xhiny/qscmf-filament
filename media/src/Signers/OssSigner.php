<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Signers;

use Quansitech\Cmf\Media\Contracts\DirectUploadSigner;

/**
 * OSS（阿里云对象存储）签名器：PostObject policy 表单签名 + 查询串签名 URL。
 *
 * PostObject：policy（base64 JSON，含 expiration 与 key/大小/Content-Type 约束），
 * Signature = base64(HMAC-SHA1(secret, base64(policy)))。
 */
class OssSigner implements DirectUploadSigner
{
    public function signUpload(string $key, string $mime, int $size, array $config, array $options = []): array
    {
        $expires = (int) config('cmf-media.sign_expires', 600);

        /** @var string $accessKey */
        $accessKey = $config['key'] ?? '';
        /** @var string $secret */
        $secret = $config['secret'] ?? '';
        /** @var string $bucket */
        $bucket = $config['bucket'] ?? '';
        /** @var string $endpoint */
        $endpoint = $config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com';

        $expiration = gmdate('Y-m-d\TH:i:s.000\Z', time() + $expires);

        // Content-Disposition / Cache-Control 写入对象元数据：PostObject 下必须是
        // 表单字段且必须出现在 policy conditions（eq 精确匹配），否则 403
        // InvalidPolicyDocument；字段值与条件值必须严格一致
        $metaHeaders = array_filter([
            'Content-Disposition' => $options['disposition'] ?? null,
            'Cache-Control' => $options['cache_control'] ?? null,
        ]);

        $conditions = [
            ['bucket' => $bucket],
            ['eq', '$key', $key],
            ['eq', '$Content-Type', $mime],
        ];

        foreach ($metaHeaders as $header => $value) {
            $conditions[] = ['eq', '$'.$header, $value];
        }

        $conditions[] = ['content-length-range', $size, $size];

        $policy = base64_encode(json_encode([
            'expiration' => $expiration,
            'conditions' => $conditions,
        ], JSON_THROW_ON_ERROR));

        return [
            'method' => 'POST',
            'upload_url' => "https://{$bucket}.{$endpoint}",
            'fields' => [
                'key' => $key,
                'policy' => $policy,
                'OSSAccessKeyId' => $accessKey,
                'Signature' => $this->policySignature($policy, $secret),
                'Content-Type' => $mime,
                ...$metaHeaders,
                'success_action_status' => '200',
            ],
            'headers' => [],
            'expires' => $expires,
        ];
    }

    public function signUrl(string $method, string $key, array $config, ?int $expires = null): string
    {
        $expires ??= (int) config('cmf-media.sign_expires', 600);

        /** @var string $accessKey */
        $accessKey = $config['key'] ?? '';
        /** @var string $secret */
        $secret = $config['secret'] ?? '';
        /** @var string $bucket */
        $bucket = $config['bucket'] ?? '';
        /** @var string $endpoint */
        $endpoint = $config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com';

        $expiresAt = time() + $expires;

        $stringToSign = strtoupper($method)."\n\n\n{$expiresAt}\n/{$bucket}/{$key}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secret, true));

        return "https://{$bucket}.{$endpoint}/{$key}"
            .'?OSSAccessKeyId='.rawurlencode($accessKey)
            ."&Expires={$expiresAt}"
            .'&Signature='.rawurlencode($signature);
    }

    /**
     * PostObject policy 签名（阿里云官方算法）：base64(HMAC-SHA1(secret, policy))。
     */
    protected function policySignature(string $base64Policy, string $secret): string
    {
        return base64_encode(hash_hmac('sha1', $base64Policy, $secret, true));
    }
}
