<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Quansitech\Cmf\Media\Support\MediaManager;
use Quansitech\Cmf\Media\Support\UploadRule;
use Throwable;

/**
 * 媒体资源：内容 MD5 去重，对象 key 即 hash 路径；引用计数归零后
 * 软删并延迟派发 DeleteMediaJob 清理云端对象。
 *
 * @property int $id
 * @property string $disk 存储驱动：tos / oss / cos / local
 * @property string $path 对象 key：{hash前2位}/{hash}[.{disposition}].{ext}
 * @property string $hash 文件内容 MD5
 * @property string $disposition 访问行为（inline / attachment / 空=无），去重维度之一
 * @property string $original_name
 * @property string $mime
 * @property string $ext
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploader_id
 * @property int $ref_count
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cmf_media';

    /** @var list<string> */
    protected $fillable = ['disk', 'path', 'hash', 'disposition', 'original_name', 'mime', 'ext', 'size', 'width', 'height', 'uploader_id', 'ref_count'];

    protected static function booted(): void
    {
        // 软删（引用归零 / 后台删除）即排期延迟清理；Job 执行前会复查引用计数
        static::deleted(function (Media $media): void {
            if ($media->isForceDeleting() || $media->ref_count > 0) {
                return;
            }

            $media->scheduleDeletion();
        });
    }

    /**
     * 延迟派发云端对象删除任务（延迟时长由 config 控制）。
     */
    public function scheduleDeletion(): void
    {
        DeleteMediaJob::dispatch($this->id)
            ->delay(now()->addMinutes((int) config('cmf-media.delete_delay_minutes', 60)));
    }

    /**
     * 对象存储 disk 名称（cmf-media-{driver}）。
     */
    public static function diskName(?string $driver = null): string
    {
        return 'cmf-media-'.($driver ?: (string) config('cmf-media.default', 'tos'));
    }

    /**
     * 对象 key：[{directory}/]{hash前2位}/{hash}[.{disposition}].{ext}。
     *
     * disposition（inline / attachment）参与 key 生成：同一内容按访问行为各存
     * 一个对象，各自在上传时写入 Content-Disposition 元数据（云厂商 response-*
     * URL 覆盖实测不可靠）。无 disposition 时保持原 key 格式，存量对象兼容。
     *
     * directory 为入口规则的静态目录段（如 t4tstudio、A/B/C，多级段即递归
     * 目录）：目录段不参与判重，仅决定新上传的落点；空 = 桶根原格式，
     * 与存量逐字节一致。
     */
    public static function objectKey(string $hash, string $ext, string $disposition = '', string $directory = ''): string
    {
        $key = substr($hash, 0, 2).'/'.$hash
            .($disposition === '' ? '' : '.'.$disposition)
            .($ext === '' ? '' : '.'.$ext);

        return $directory === '' ? $key : $directory.'/'.$key;
    }

    /**
     * 启用 CDN 子目录关联（cdn.path_prefix）时，对象必须落在该目录下，否则边缘
     * 必然 404。上传期就拒绝，避免脏对象进库后成片空白；空前缀（默认）不校验。
     */
    public static function assertKeyWithinCdnPrefix(string $key, string $driver): void
    {
        $cdn = config('filesystems.disks.'.self::diskName($driver).'.cdn');

        // 与 adapter 的启用条件同源：CDN 出口未启用时 url() 走原生全 Key，
        // 前缀不参与拼接，桶根对象照常可用，不应拒绝上传
        if (! is_array($cdn) || ! MediaManager::cdnEnabled($cdn)) {
            return;
        }

        $prefix = MediaManager::normalizeCdnPathPrefix($cdn['path_prefix'] ?? '');

        if ($prefix === '' || str_starts_with($key, $prefix.'/')) {
            return;
        }

        throw ValidationException::withMessages([
            'path' => "对象落点 {$key} 不在 CDN 子目录 {$prefix}/ 下：检查该入口规则的 path，或清空 COS_CDN_PATH_PREFIX",
        ]);
    }

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class, 'media_id');
    }

    /**
     * 上传人（宿主用户模型，可为空）。
     *
     * @return BelongsTo<Model, $this>
     */
    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model') ?: User::class;

        return $this->belongsTo($model, 'uploader_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * 访问 URL：优先公开 URL，回退临时签名 URL。
     *
     * 注意顺序不能反过来：缩略图依赖在 URL 后拼接云厂商图片处理参数
     * （x-tos-process / x-oss-process / imageMogr2），而 SigV4 预签名 URL
     * 追加任何查询参数都会破坏签名导致 403。媒体文件按公开可读设计。
     */
    public function url(int $ttl = 600): ?string
    {
        $diskName = static::diskName($this->disk);

        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $error) {
            if (config("filesystems.disks.{$diskName}") !== null) {
                $this->logUrlFailure($diskName, [$error], [
                    'media_id' => $this->id,
                    'disk' => $this->disk,
                    'path' => $this->path,
                    'exception' => $error,
                ]);
            }

            return null;
        }

        try {
            return $disk->url($this->path);
        } catch (Throwable $urlError) {
            try {
                return $disk->temporaryUrl($this->path, now()->addSeconds($ttl));
            } catch (Throwable $temporaryError) {
                $this->logUrlFailure($diskName, [$urlError, $temporaryError], [
                    'media_id' => $this->id,
                    'disk' => $this->disk,
                    'path' => $this->path,
                    'exception' => $urlError,
                    'temporary_url_exception' => $temporaryError,
                ]);

                return null;
            }
        }
    }

    /**
     * 同一 disk 上按根因（异常类型 + 消息指纹）各记一条；去重状态存容器，
     * Web 每请求复位，队列/CLI 下随进程存活。
     *
     * @param  list<Throwable>  $errors
     * @param  array<string, mixed>  $context
     */
    private function logUrlFailure(string $diskName, array $errors, array $context): void
    {
        $reason = implode('/', array_map(
            fn (Throwable $error): string => $error::class.':'.substr(md5($error->getMessage()), 0, 8),
            $errors
        ));

        $marker = "cmf-media.url-failure.{$diskName}.{$reason}";

        if (app()->bound($marker)) {
            return;
        }

        app()->instance($marker, true);

        Log::warning('媒体 URL 生成失败，已退化为 null', $context);
    }

    /**
     * 按入口规则（cmf-media.rules）生成访问 URL。
     *
     * 访问行为（缓存时长 / 预览或下载 / 下载文件名）已在上传时写入对象元数据
     * （disposition 同时是去重维度，不同入口各存各的对象），云驱动直接返回
     * 公开 URL 即可——不再拼 response-* 覆盖参数（TOS 实测匿名 GET 带
     * response-* 参数返回 400，且默认域名投递层强制 attachment）。
     * local 驱动仍走 cmf-media.file 路由，由服务端输出对应响应头。
     *
     * 规则为空或未声明访问行为时与 url() 完全一致（存量行为不变）。
     */
    public function urlForEntry(?string $rule, int $ttl = 600): ?string
    {
        $uploadRule = UploadRule::find($rule);

        if (! $uploadRule instanceof UploadRule || ! $uploadRule->hasAccessBehavior()) {
            return $this->url($ttl);
        }

        if ($this->disk === 'local') {
            return route('cmf-media.file', ['media' => $this->id, 'rule' => $uploadRule->name]);
        }

        return $this->url($ttl);
    }

    /**
     * 缩略图 URL：直传绕开服务器无法本地生成缩略图，
     * 使用云厂商图片处理参数（OSS/COS/TOS 均支持 URL 参数缩放）。
     */
    public function thumbUrl(): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        $url = $this->url();

        if (! is_string($url)) {
            return null;
        }

        /** @var string $suffix */
        $suffix = config("cmf-media.disks.{$this->disk}.thumb_suffix", '');

        if ($suffix === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&'.ltrim($suffix, '?&') : $suffix);
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'ref_count' => 'integer',
        ];
    }
}
