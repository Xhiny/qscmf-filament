<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 按入口上传规则（cmf-media.rules.{name}）。
 *
 * 入口（MediaPicker::rule('名') / API 请求的 rule 参数）声明使用哪套规则；
 * 规则只能比全局白名单更严——mimes 解析时校验必须是全局 allowed_mimes 的
 * 子集、max_size 取与全局的最小值，因此客户端声明哪套规则不会放大权限，
 * 未声明规则的入口沿用全局限制（存量行为不变）。
 *
 * 访问行为（缓存时长 / 预览或下载 / 下载文件名）在上传时写入对象元数据：
 * disposition 是去重维度之一（同一内容按 inline / attachment / 无各存一个
 * 对象，各自元数据正确）；cache_seconds 随上传写入 Cache-Control 头（不纳入
 * 去重，同内容多入口时以先上传者为准）。云厂商 response-* URL 覆盖实测不可
 * 靠（TOS 匿名 GET 带 response-* 参数返回 400），local 驱动仍由 file 路由
 * 在访问时输出响应头。
 */
class UploadRule
{
    /**
     * @param  list<string>  $mimes  允许的 MIME（支持 "image/*" 通配），全局白名单子集
     * @param  'inline'|'attachment'|null  $disposition  访问行为：预览 / 强制下载
     */
    public function __construct(
        public readonly string $name,
        public readonly array $mimes,
        public readonly int $maxSize,
        public readonly ?int $cacheSeconds,
        public readonly ?string $disposition,
    ) {}

    /**
     * 按名称解析；未配置 / 配置非法（超出全局白名单）视为配置错误，抛 InvalidArgumentException。
     */
    public static function resolve(string $name): self
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $name) !== 1) {
            throw new InvalidArgumentException("非法的媒体上传规则名：{$name}");
        }

        /** @var mixed $config */
        $config = config("cmf-media.rules.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("未知的媒体上传规则：{$name}（cmf-media.rules 中未配置）");
        }

        /** @var list<string> $mimes */
        $mimes = array_values(array_filter((array) ($config['mimes'] ?? []), 'is_string'));

        /** @var list<string> $globalMimes */
        $globalMimes = config('cmf-media.allowed_mimes', []);

        foreach ($mimes as $pattern) {
            if (! static::patternCovered($pattern, $globalMimes)) {
                throw new InvalidArgumentException(
                    "上传规则 {$name} 的 MIME「{$pattern}」超出全局白名单 cmf-media.allowed_mimes，入口规则只能比全局更严"
                );
            }
        }

        // 规则只能更严：超过全局上限时按全局收紧
        $maxSize = min(
            (int) ($config['max_size'] ?? config('cmf-media.max_size')),
            (int) config('cmf-media.max_size'),
        );

        /** @var mixed $disposition */
        $disposition = $config['disposition'] ?? null;

        return new self(
            name: $name,
            mimes: $mimes,
            maxSize: $maxSize,
            cacheSeconds: isset($config['cache_seconds']) ? max(0, (int) $config['cache_seconds']) : null,
            disposition: in_array($disposition, ['inline', 'attachment'], true) ? $disposition : null,
        );
    }

    /**
     * 无规则名 → null（沿用全局限制）；有名称 → resolve（未知名抛 InvalidArgumentException）。
     */
    public static function find(?string $name): ?self
    {
        return ($name === null || $name === '') ? null : static::resolve($name);
    }

    /**
     * 请求参数解析：未知名称转为 422（客户端可感知），区别于 find 的异常。
     */
    public static function fromRequest(?string $name): ?self
    {
        try {
            return static::find($name);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rule' => $e->getMessage()]);
        }
    }

    /**
     * MIME 校验：全局白名单 ∧ 规则白名单（交集，保证只能更严）。
     */
    public function allowsMime(string $mime): bool
    {
        return MediaManager::mimeAllowed($mime) && MediaManager::mimeMatches($mime, $this->mimes);
    }

    public function allowsSize(int $size): bool
    {
        return $size >= 1 && $size <= $this->maxSize;
    }

    /**
     * 统一的上传校验入口（sign / upload / callback 复用；check 仅在声明规则时调用）。
     * 无规则时等价于原全局校验，存量行为不变。
     */
    public static function validateUpload(?self $rule, string $mime, int $size): void
    {
        $maxSize = $rule?->maxSize ?? (int) config('cmf-media.max_size');

        if ($size < 1 || $size > $maxSize) {
            throw ValidationException::withMessages([
                'size' => '文件超过大小上限 '.($maxSize / 1024 / 1024).'MB',
            ]);
        }

        $allowed = $rule ? $rule->allowsMime($mime) : MediaManager::mimeAllowed($mime);

        if (! $allowed) {
            throw ValidationException::withMessages([
                'mime' => '不允许的文件类型：'.$mime,
            ]);
        }
    }

    /**
     * 前端文件选择的 accept 过滤值（"image/*,video/mp4"）；空规则返回 ''。
     */
    public function acceptAttribute(): string
    {
        return implode(',', $this->mimes);
    }

    public function hasAccessBehavior(): bool
    {
        return $this->cacheSeconds !== null || $this->disposition !== null;
    }

    public function cacheControl(): ?string
    {
        return $this->cacheSeconds === null ? null : "max-age={$this->cacheSeconds}, public";
    }

    /**
     * Content-Disposition 头值；attachment 带原始文件名（RFC 5987 编码）。
     */
    public function contentDisposition(Media $media): ?string
    {
        return $this->contentDispositionForName($media->original_name);
    }

    /**
     * 按原始文件名生成 Content-Disposition 头值（sign 阶段还没有 Media 记录）。
     */
    public function contentDispositionForName(string $originalName): ?string
    {
        if ($this->disposition === null) {
            return null;
        }

        if ($this->disposition === 'inline') {
            return 'inline';
        }

        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $originalName) ?: 'file';
        $fallback = str_replace(['"', '\\', ';'], '_', $fallback);

        return "attachment; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($originalName);
    }

    /**
     * 访问响应头（local 驱动 file 路由输出用）。
     *
     * @return array<string, string>
     */
    public function accessHeaders(Media $media): array
    {
        return array_filter([
            'Cache-Control' => $this->cacheControl(),
            'Content-Disposition' => $this->contentDisposition($media),
        ]);
    }

    /**
     * 规则 pattern 是否被全局白名单覆盖（保证规则只能更严）：
     * 精确相等，或全局为 "x/*" 通配且 pattern 属于该族（"x/*" 或 "x/yyy"）。
     *
     * @param  list<string>  $globalPatterns
     */
    protected static function patternCovered(string $pattern, array $globalPatterns): bool
    {
        foreach ($globalPatterns as $global) {
            if ($global === $pattern) {
                return true;
            }

            if (str_ends_with($global, '/*') && str_starts_with($pattern, substr($global, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
