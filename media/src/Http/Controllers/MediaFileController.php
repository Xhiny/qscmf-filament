<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\UploadRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * local 驱动文件的访问行为输出：按入口规则（?rule=名）附加
 * Cache-Control / Content-Disposition（预览或下载、原始文件名）。
 * 云驱动的访问行为已落对象元数据，重定向到公开 URL 即可。
 *
 * 媒体文件按公开可读设计（同云桶公开读），路由默认仅 web 中间件，
 * 需要收口时用 cmf-media.file_middleware 调整。
 */
class MediaFileController extends Controller
{
    public function show(Request $request, int $media): Response
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        /** @var Media $mediaModel */
        $mediaModel = $model::query()->findOrFail($media);

        $rule = UploadRule::fromRequest($request->query('rule'));

        if ($mediaModel->disk !== 'local') {
            // 云驱动：访问行为在对象元数据上，直接重定向到公开 URL
            $url = $mediaModel->urlForEntry($rule?->name);
            abort_if($url === null, 404);

            return redirect()->away($url);
        }

        $disk = Storage::disk(Media::diskName('local'));
        abort_unless($disk->exists($mediaModel->path), 404);

        $response = $disk->response($mediaModel->path);

        // response() 会按 inline+对象名 生成默认 Content-Disposition，规则头后写覆盖
        foreach (($rule?->accessHeaders($mediaModel) ?? []) as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
