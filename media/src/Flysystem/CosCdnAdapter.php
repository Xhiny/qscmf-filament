<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Flysystem;

use Overtrue\Flysystem\Cos\CosAdapter;
use Quansitech\Cmf\Media\Cdn\CdnAuthSigner;
use Quansitech\Cmf\Media\Cdn\CdnSignerFactory;
use Quansitech\Cmf\Media\Support\MediaManager;

/**
 * COS + CDN 加速域名访问适配器。
 *
 * 私有桶场景 COS 默认域名匿名访问 403，且默认域名投递层强制
 * Content-Disposition: attachment；绑定开启边缘鉴权的 CDN 域名后，
 * 访问 URL 由签名器（disks.cos.cdn.auth_type）动态签发。
 *
 * cdn.url 未配置时与原生 CosAdapter 行为完全一致（parent 直通）。
 */
class CosCdnAdapter extends CosAdapter
{
    private ?CdnAuthSigner $cdnSigner = null;

    private bool $cdnSignerResolved = false;

    /** 构造期归一后的回源子目录前缀（无首尾斜杠；空 = CDN 关联桶根） */
    private readonly string $cdnPathPrefix;

    public function __construct(array $config)
    {
        parent::__construct($config);

        // 半配置守卫前置到构造：盘首次使用（任意操作，含上传）即抛清晰
        // 异常，而非等到首次 url() 渲染——CosAdapter 被选中即意味着
        // url / auth_type 至少其一已配置，url 缺失（auth_type 已配置）无
        // 合法语义；签名器解析（auth_type 已配置而 primary_key 缺失）同抛
        if (rtrim((string) ($this->config['cdn']['url'] ?? ''), '/') === '') {
            throw new \InvalidArgumentException('CosCdnAdapter 已启用（cdn.auth_type 已配置）但 cdn.url 缺失：请配置 disks.cos.cdn.url（CDN 加速域名），或移除 auth_type 以使用原生 COS 访问');
        }

        $this->cdnPathPrefix = MediaManager::normalizeCdnPathPrefix($this->config['cdn']['path_prefix'] ?? '');
        $this->cdnSigner();
    }

    public function getUrl(string $path): string
    {
        $cdn = rtrim((string) ($this->config['cdn']['url'] ?? ''), '/');

        if ($cdn === '') {
            // 不回退 parent：config['cdn'] 为非空数组时 vendor getUrl 会将其当
            // 字符串 rtrim 而 TypeError；此为半配置错误，直接抛出清晰提示
            throw new \InvalidArgumentException('CosCdnAdapter 已启用（cdn.auth_type 已配置）但 cdn.url 缺失：请配置 disks.cos.cdn.url（CDN 加速域名），或移除 auth_type 以使用原生 COS 访问');
        }

        // 剥前缀后的 uri 同时用于签名与拼接——两处必须同源，剥其一必致
        // 404（双重前缀）或 403（边缘按 URL Path 复算 md5 失败）
        $uri = $this->stripPathPrefix('/'.$this->prefixer->prefixPath($path));
        $signer = $this->cdnSigner();

        return $signer instanceof CdnAuthSigner
            ? $signer->sign($cdn, $uri)
            : $cdn.$uri;
    }

    /**
     * CDN 边缘鉴权的有效期由控制台「鉴权有效时间」全局决定，无法逐 URL
     * 兑现 $expiration：有签名器时与 getUrl() 同口径；未开鉴权（或未配
     * CDN）回落 COS 原生 q-sign（parent，由 SDK 兑现过期时间）。
     */
    public function getTemporaryUrl(string $path, $expiration): string
    {
        // 合法回落仅一种：url 已配置但未开鉴权（签名器 null）→ 原生 q-sign
        // （parent 链 getSignedUrl 不触碰 config['cdn']，安全）
        if (! $this->cdnSigner() instanceof CdnAuthSigner) {
            return parent::getTemporaryUrl($path, $expiration);
        }

        return $this->getUrl($path);
    }

    /**
     * 剥掉 CDN 关联子目录前缀（完整目录段：/{prefix}/ 开头；uri 恰等于
     * /{prefix} 不算命中）。path 不在前缀之下即抛——该对象不在 CDN 关联
     * 目录内，边缘回源必 404，不应静默输出错误 URL。
     */
    private function stripPathPrefix(string $uri): string
    {
        if ($this->cdnPathPrefix === '') {
            return $uri;
        }

        $needle = '/'.$this->cdnPathPrefix.'/';
        if (str_starts_with($uri, $needle)) {
            return substr($uri, strlen($needle) - 1);
        }

        throw new \InvalidArgumentException("对象 {$uri} 不在 CDN 关联目录 {$this->cdnPathPrefix}/ 之下：CDN 边缘回源无法命中（404）。请核对 COS_CDN_PATH_PREFIX 与控制台「回源源路径」，或修正对象存放路径");
    }

    private function cdnSigner(): ?CdnAuthSigner
    {
        if (! $this->cdnSignerResolved) {
            $this->cdnSigner = CdnSignerFactory::fromConfig(is_array($this->config['cdn'] ?? null) ? $this->config['cdn'] : []);
            $this->cdnSignerResolved = true;
        }

        return $this->cdnSigner;
    }
}
