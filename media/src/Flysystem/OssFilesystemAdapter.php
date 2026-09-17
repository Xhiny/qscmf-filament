<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Flysystem;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * OSS 驱动的 Laravel 包装类。
 *
 * xxtime/flysystem-aliyun-oss 的 OssAdapter 未提供 Laravel 识别的
 * getUrl()/getTemporaryUrl() 方法（Laravel 13 的 url() 只认这两个方法
 * 或 Ftp/Sftp/Local 适配器类型），直接套通用 FilesystemAdapter 会让
 * $disk->url() 抛 "This driver does not support retrieving URLs"。
 * 这里补齐公开 URL 生成：{bucket}.{endpoint}/{path}（媒体文件按公共可读设计）。
 */
class OssFilesystemAdapter extends FilesystemAdapter
{
    public function url($path): string
    {
        // 配置了自定义域名（如绑定了 CDN / 自定义访问域名）时优先生成对应 URL
        if (isset($this->config['url'])) {
            /** @var string $url */
            $url = $this->config['url'];

            return $this->concatPathToUrl($url, $this->prefixer->prefixPath($path));
        }

        /** @var string $endpoint */
        $endpoint = $this->config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com';
        /** @var string $bucket */
        $bucket = $this->config['bucket'] ?? '';

        return "https://{$bucket}.{$endpoint}/".$this->prefixer->prefixPath($path);
    }
}
