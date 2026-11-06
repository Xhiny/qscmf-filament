<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Flysystem;

use Overtrue\Flysystem\Cos\CosAdapter;
use Quansitech\Cmf\Media\Cdn\CdnAuthSigner;
use Quansitech\Cmf\Media\Cdn\CdnSignerFactory;

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

        $uri = '/'.$this->prefixer->prefixPath($path);
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

    private function cdnSigner(): ?CdnAuthSigner
    {
        if (! $this->cdnSignerResolved) {
            $this->cdnSigner = CdnSignerFactory::fromConfig(is_array($this->config['cdn'] ?? null) ? $this->config['cdn'] : []);
            $this->cdnSignerResolved = true;
        }

        return $this->cdnSigner;
    }
}
