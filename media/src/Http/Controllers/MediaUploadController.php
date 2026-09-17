<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Contracts\ObjectInspector;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaManager;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;

/**
 * 浏览器直传三端点：check（查重秒传）/ sign（签发凭证）/ callback（建档），
 * 以及 MediaPicker「从媒体库选择」的 library 列表。
 */
class MediaUploadController extends Controller
{
    use AuthorizesRequests;

    /**
     * 查重：命中（含软删记录）直接返回已有 media（秒传），未命中 404。
     * 声明了入口规则时秒传同样受规则约束（不改客户端参数绕不过）。
     */
    public function check(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('viewAny', $model);

        /** @var array{hash: string, size: int, mime: string, rule?: string|null} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'size' => ['required', 'integer', 'min:0'],
            'mime' => ['required', 'string', 'max:100'],
            'rule' => ['nullable', 'string', 'max:50'],
        ]);

        // 未声明规则的入口保持原样（check 历史上不校验类型/大小），不影响存量
        if ($rule = UploadRule::fromRequest($data['rule'] ?? null)) {
            UploadRule::validateUpload($rule, $data['mime'], max(1, (int) $data['size']));
        }

        /** @var Media|null $media */
        $media = $model::withTrashed()
            ->where('hash', $data['hash'])
            ->where('disposition', $rule?->disposition ?? '')
            ->first();

        if (! $media instanceof Media) {
            return response()->json(['message' => '未命中'], 404);
        }

        if ($media->trashed()) {
            // 命中软删记录：恢复，DeleteMediaJob 复查时会自然跳过
            $media->restore();
        }

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 签发直传凭证：按入口规则（未声明则全局）校验 mime/大小，对象 key 为
     * {hash前2位}/{hash}[.{disposition}].{ext}，访问行为头随凭证一并下发。
     */
    public function sign(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        /** @var array{hash: string, name: string, mime: string, size: int, rule?: string|null} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
            'rule' => ['nullable', 'string', 'max:50'],
        ]);

        // 服务端强制：绕过前端直接调接口同样按入口规则拒绝
        $rule = UploadRule::fromRequest($data['rule'] ?? null);
        UploadRule::validateUpload($rule, $data['mime'], (int) $data['size']);

        $ext = MediaManager::safeExtension($data['name']);
        // disposition 参与对象 key：同一内容按访问行为各存一个对象（去重维度）
        $key = Media::objectKey($data['hash'], $ext, $rule?->disposition ?? '');
        $driver = MediaManager::driver();

        // local：无需云签名，直传应用服务器的 upload 端点（POST multipart）；
        // rule 随表单字段透传，中转上传与直传执行同一套入口规则
        if (MediaManager::isLocal($driver)) {
            return response()->json([
                'method' => 'POST',
                'upload_url' => route('cmf-media.upload'),
                'fields' => array_filter([
                    'hash' => $data['hash'],
                    'name' => $data['name'],
                    'rule' => $rule?->name,
                ]),
                'headers' => ['X-CSRF-TOKEN' => csrf_token(), 'Accept' => 'application/json'],
                'expires' => 0,
                'path' => $key,
                'disk' => $driver,
                'local' => true,
            ]);
        }

        // 访问行为（预览或下载 / 缓存时长）随上传写入对象元数据：
        // 云厂商 response-* URL 覆盖实测不可靠（TOS 匿名 GET 直接 400）
        $credential = MediaManager::signer($driver)
            ->signUpload($key, $data['mime'], (int) $data['size'], MediaManager::diskConfig($driver), [
                'disposition' => $rule?->contentDispositionForName($data['name']),
                'cache_control' => $rule?->cacheControl(),
            ]);

        return response()->json([...$credential, 'path' => $key, 'disk' => $driver]);
    }

    /**
     * local 驱动上传端点：服务器接收文件、计算真实内容 hash（防伪）、
     * 落盘到 hash 路径并建档。秒传去重由前置 check 承担，此处相同内容
     * 重复上传时复用已有记录（hash 唯一索引兜底）。
     */
    public function upload(Request $request, MediaUploader $uploader): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        if (! MediaManager::isLocal()) {
            abort(404);
        }

        /** @var array{hash: string, name: string, rule?: string|null} $data */
        $data = $request->validate([
            'file' => ['required', 'file'],
            'hash' => ['required', 'string', 'size:32'],
            'name' => ['required', 'string', 'max:255'],
            'rule' => ['nullable', 'string', 'max:50'],
        ]);

        $media = $uploader->store(
            $request->file('file'),
            clientHash: $data['hash'],
            originalName: $data['name'],
            uploaderId: $request->user()?->getAuthIdentifier(),
            rule: UploadRule::fromRequest($data['rule'] ?? null),
        );

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 直传完成回调建档：校验对象真实存在、size 一致、ETag 与上报 hash 一致（防伪），
     * hash 唯一索引兜底并发重复回调。
     */
    public function callback(Request $request, ObjectInspector $inspector, MediaUploader $uploader): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        /** @var array{hash: string, path: string, name: string, mime: string, size: int, width?: int|null, height?: int|null, rule?: string|null} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'path' => ['required', 'string', 'max:512'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],
            'rule' => ['nullable', 'string', 'max:50'],
        ]);

        // 回调建档前再按入口规则校验一次（纵深防御；直传已在 sign 环节强制）
        $rule = UploadRule::fromRequest($data['rule'] ?? null);
        UploadRule::validateUpload($rule, $data['mime'], (int) $data['size']);

        // 对象 key 由服务端规则生成（含 disposition 维度），防止客户端伪造路径冒领他人文件
        $ext = MediaManager::safeExtension($data['name']);
        $expectedKey = Media::objectKey($data['hash'], $ext, $rule?->disposition ?? '');

        if ($data['path'] !== $expectedKey) {
            throw ValidationException::withMessages(['path' => '对象路径与内容哈希不符']);
        }

        $driver = MediaManager::driver();
        $info = $inspector->inspect(Media::diskName($driver), $data['path']);

        if ($info === null) {
            throw ValidationException::withMessages(['path' => '云端对象不存在，建档失败']);
        }

        if ($info['size'] !== (int) $data['size']) {
            throw ValidationException::withMessages(['size' => '云端对象大小与上报不符']);
        }

        // 单 PUT 上传时 ETag 即内容 MD5（分片 ETag 含 "-"，跳过比对）
        $etag = $info['etag'];

        if (is_string($etag) && ! str_contains($etag, '-') && ! hash_equals(strtolower($etag), strtolower($data['hash']))) {
            throw ValidationException::withMessages(['hash' => '云端对象指纹与上报哈希不符']);
        }

        $media = $uploader->firstOrCreate(
            $driver,
            $expectedKey,
            $data,
            $ext,
            $request->user()?->getAuthIdentifier(),
            $rule?->disposition ?? '',
        );

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 媒体库列表（MediaPicker「从媒体库选择」）。
     */
    public function library(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('viewAny', $model);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $paginator = $model::query()
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 24))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Media $media): array => $this->mediaJson($media))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @return class-string<Media>
     */
    protected function model(): string
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaJson(Media $media): array
    {
        return [
            'id' => $media->id,
            'disk' => $media->disk,
            'path' => $media->path,
            'hash' => $media->hash,
            'original_name' => $media->original_name,
            'mime' => $media->mime,
            'ext' => $media->ext,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'ref_count' => $media->ref_count,
            'url' => $media->url(),
            'thumb_url' => $media->thumbUrl(),
        ];
    }
}
