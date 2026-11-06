<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Support;

use InvalidArgumentException;
use Quansitech\Cmf\Media\Contracts\DirectUploadSigner;
use Quansitech\Cmf\Media\Signers\CosSigner;
use Quansitech\Cmf\Media\Signers\OssSigner;
use Quansitech\Cmf\Media\Signers\TosSigner;

/**
 * 媒体模块的运行时解析：驱动 → 签名器 / disk 配置。
 */
class MediaManager
{
    /** @var array<string, class-string<DirectUploadSigner>> */
    protected const SIGNERS = [
        'tos' => TosSigner::class,
        'oss' => OssSigner::class,
        'cos' => CosSigner::class,
    ];

    /**
     * 当前默认驱动（tos / oss / cos / local）。
     */
    public static function driver(): string
    {
        return (string) config('cmf-media.default', 'tos');
    }

    /**
     * 是否本地磁盘驱动：无签名器，上传走服务器端点（POST upload）。
     */
    public static function isLocal(?string $driver = null): bool
    {
        return ($driver ?? static::driver()) === 'local';
    }

    public static function signer(?string $driver = null): DirectUploadSigner
    {
        $driver ??= static::driver();

        $signerClass = static::SIGNERS[$driver]
            ?? throw new InvalidArgumentException("不支持的媒体驱动：{$driver}（local 驱动无需签名器，请走 upload 端点）");

        return app($signerClass);
    }

    /**
     * @return array<string, mixed>
     */
    public static function diskConfig(?string $driver = null): array
    {
        $driver ??= static::driver();

        /** @var array<string, mixed> $config */
        $config = config("cmf-media.disks.{$driver}", []);

        return $config;
    }

    /**
     * CDN 出口是否启用：url 或 auth_type 任一非空即换 CDN 适配器
     * （与 ServiceProvider 选 adapter 的判定同源）。
     *
     * @param  array<string, mixed>  $cdn
     */
    public static function cdnEnabled(array $cdn): bool
    {
        return $cdn !== []
            && ((string) ($cdn['url'] ?? '') !== '' || (string) ($cdn['auth_type'] ?? '') !== '');
    }

    /**
     * CDN 子目录前缀归一：env 带引号时空白会让 needle 永不匹配，首尾斜杠会让
     * 剥离与拼接错位。
     */
    public static function normalizeCdnPathPrefix(mixed $value): string
    {
        return trim((string) $value, " \t\n\r\0\x0B/");
    }

    /**
     * 已配置（云驱动凭证非空；local 恒可用）的驱动列表。
     *
     * @return list<string>
     */
    public static function configuredDrivers(): array
    {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = config('cmf-media.disks', []);

        return collect($disks)
            ->filter(fn (array $config, string $driver): bool => $driver === 'local' || filled($config['bucket'] ?? null))
            ->keys()
            ->all();
    }

    /**
     * MIME 白名单校验（支持 "image/*" 通配）。
     */
    public static function mimeAllowed(string $mime): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('cmf-media.allowed_mimes', []);

        return static::mimeMatches($mime, $allowed);
    }

    /**
     * MIME 是否命中给定白名单（支持 "image/*" 通配）。
     *
     * @param  list<string>  $patterns
     */
    public static function mimeMatches(string $mime, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $mime) {
                return true;
            }

            if (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从原始文件名提取安全的扩展名（小写字母数字，最长 20）。
     */
    public static function safeExtension(string $originalName): string
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,20}$/', $ext) === 1 ? $ext : '';
    }
}
