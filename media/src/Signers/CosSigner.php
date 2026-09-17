<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Signers;

use Quansitech\Cmf\Media\Contracts\DirectUploadSigner;

/**
 * COS（腾讯云对象存储）签名器：q-sign-algorithm=sha1 预签名 PUT / 签名 URL。
 *
 * SignKey = HMAC-SHA1(SecretKey, KeyTime)
 * HttpString = "{method}\n/{key}\n\n\n"
 * StringToSign = "sha1\n{KeyTime}\n{sha1(HttpString)}\n"
 * Signature = HMAC-SHA1(SignKey, StringToSign)
 */
class CosSigner implements DirectUploadSigner
{
    public function signUpload(string $key, string $mime, int $size, array $config, array $options = []): array
    {
        $expires = (int) config('cmf-media.sign_expires', 600);

        // Content-Disposition / Cache-Control 随 PUT 写入对象元数据；q-header-list
        // 为空即不签任何请求头（现状 Content-Type 也未签名且工作正常），直接附加即可
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

        /** @var string $secretId */
        $secretId = $config['secret_id'] ?? '';
        /** @var string $secretKey */
        $secretKey = $config['secret_key'] ?? '';
        /** @var string $region */
        $region = $config['region'] ?? 'ap-guangzhou';
        /** @var string $bucket */
        $bucket = $config['bucket'] ?? '';

        $keyTime = time().';'.(time() + $expires);
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);

        $httpString = strtolower($method)."\n/{$key}\n\n\n";
        $stringToSign = "sha1\n{$keyTime}\n".sha1($httpString)."\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        $auth = 'q-sign-algorithm=sha1'
            .'&q-ak='.rawurlencode($secretId)
            ."&q-sign-time={$keyTime}"
            ."&q-key-time={$keyTime}"
            .'&q-header-list='
            .'&q-url-param-list='
            ."&q-signature={$signature}";

        return "https://{$bucket}.cos.{$region}.myqcloud.com/{$key}?{$auth}";
    }
}
