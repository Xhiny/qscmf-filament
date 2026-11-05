<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 服务器端收文件建档：计算真实内容 hash（防伪）、按 hash 路径落盘、
 * firstOrCreate 建档（含软删恢复与唯一索引并发兜底）。
 *
 * 供 local 驱动 upload 端点与 RichEditor 附件接管（FileAttachmentProvider）
 * 复用；云直传 callback 的建档也经 firstOrCreate() 收敛。
 */
class MediaUploader
{
    /**
     * 接收一个已在服务器上的上传文件（含 Livewire TemporaryUploadedFile）：
     * 服务端计算内容指纹 → 命中已有记录直接复用（秒传去重）→ 落盘建档。
     *
     * @param  string|null  $clientHash  客户端上报 hash（浏览器直传场景防伪校验）；null 表示无上报
     * @param  string|null  $originalName  覆盖原始文件名（默认取上传文件的客户端文件名）
     * @param  UploadRule|null  $rule  入口上传规则（cmf-media.rules）；null 沿用全局限制
     */
    public function store(UploadedFile $file, ?string $clientHash = null, ?string $originalName = null, int|string|null $uploaderId = null, ?UploadRule $rule = null): Media
    {
        $size = (int) $file->getSize();

        // MIME 以服务端探测为准，客户端上报不可信
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        UploadRule::validateUpload($rule, $mime, $size);

        // Livewire 临时盘配置为云盘时 realPath 非本地文件，退化为按内容计算
        $realPath = $file->getRealPath();
        $isLocalFile = is_string($realPath) && is_file($realPath);

        // 服务器亲自计算内容指纹：与客户端上报不符即拒绝
        $hash = $isLocalFile ? md5_file($realPath) : md5($file->get());

        if (! is_string($hash)) {
            throw ValidationException::withMessages(['hash' => '文件指纹计算失败']);
        }

        if ($clientHash !== null && ! hash_equals(strtolower($hash), strtolower($clientHash))) {
            throw ValidationException::withMessages(['hash' => '文件指纹与上报哈希不符']);
        }

        $name = $originalName ?? $file->getClientOriginalName();
        $ext = MediaManager::safeExtension($name);
        $disposition = $rule?->disposition ?? '';
        $key = Media::objectKey($hash, $ext, $disposition, $rule?->path ?? '');
        $driver = MediaManager::driver();

        // 先查后写：命中已有记录（含软删）直接复用，不落盘新对象。跨目录重复
        // 上传（如 RichEditor 附件无 check 前置）不能先写再查——那会在复用旧
        // 记录的同时把新 path 下的对象留给云上，成为清理任务永远扫不到的孤儿。
        $existing = $this->findExisting($hash, $disposition);
        if ($existing instanceof Media) {
            return $this->reuseExisting($existing, $key, $driver);
        }

        $disk = Storage::disk(Media::diskName($driver));

        if (! $disk->exists($key)) {
            if ($isLocalFile) {
                $stream = fopen($realPath, 'r');
                $disk->put($key, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            } else {
                $disk->put($key, $file->get());
            }
        }

        // 图片尺寸服务端直接读取
        $width = $height = null;

        if (str_starts_with($mime, 'image/')) {
            $imageSize = $isLocalFile
                ? @getimagesize($realPath)
                : @getimagesizefromstring($file->get());

            if (is_array($imageSize)) {
                [$width, $height] = [$imageSize[0], $imageSize[1]];
            }
        }

        return $this->firstOrCreate($driver, $key, [
            'hash' => $hash,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ], $ext, $uploaderId, $disposition);
    }

    /**
     * 按 hash + disposition 建档：已有记录（含软删）直接复用并恢复；
     * 唯一索引兜底并发重复建档。
     *
     * @param  array{hash: string, name: string, mime: string, size: int, width?: int|null, height?: int|null}  $data
     */
    public function firstOrCreate(string $driver, string $key, array $data, string $ext, int|string|null $uploaderId, string $disposition = ''): Media
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        // 含软删记录：同 hash + disposition 重复上传直接复用并恢复
        /** @var Media|null $existing */
        $existing = $this->findExisting($data['hash'], $disposition);

        if ($existing instanceof Media) {
            return $this->reuseExisting($existing, $key, $driver);
        }

        try {
            /** @var Media $media */
            $media = $model::create([
                'hash' => $data['hash'],
                'disposition' => $disposition,
                'disk' => $driver,
                'path' => $key,
                'original_name' => $data['name'],
                'mime' => $data['mime'],
                'ext' => $ext,
                'size' => $data['size'],
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'uploader_id' => $uploaderId,
            ]);
        } catch (QueryException) {
            // 并发重复建档撞唯一索引：改查已有记录
            /** @var Media $media */
            $media = $model::withTrashed()
                ->where('hash', $data['hash'])
                ->where('disposition', $disposition)
                ->firstOrFail();

            return $this->reuseExisting($media, $key, $driver);
        }

        if ($media->trashed()) {
            $media->restore();
        }

        return $media;
    }

    /**
     * 按 hash + disposition 查已有记录（含软删）。
     */
    private function findExisting(string $hash, string $disposition): ?Media
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        /** @var Media|null */
        return $model::withTrashed()
            ->where('hash', $hash)
            ->where('disposition', $disposition)
            ->first();
    }

    /**
     * 复用已有记录：恢复软删；若本次写入的对象 key 与记录冻结的 path 不同
     * （跨目录重复上传、或查重与建档之间的并发窗口），删除本次多余对象并
     * 记日志——否则它会成为无记录指向、清理任务也扫不到的孤儿。
     */
    private function reuseExisting(Media $existing, string $key, string $driver): Media
    {
        if ($existing->trashed()) {
            $existing->restore();
        }

        if ($existing->path !== $key) {
            Storage::disk(Media::diskName($driver))->delete($key);
            Log::warning('cmf-media: 复用已有媒体记录，删除本次重复写入的对象', [
                'media_id' => $existing->getKey(),
                'record_path' => $existing->path,
                'orphan_key' => $key,
            ]);
        }

        return $existing;
    }
}
