<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\RichContent\MediaFileAttachmentProvider;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\Article;

beforeEach(function (): void {
    config(['cmf-media.default' => 'local']);
    Storage::fake('cmf-media-local');
    Storage::fake('tmp-for-tests');

    actingAsTestUser();
});

/**
 * 构造 Livewire 临时上传文件（RichEditor 拖入/粘贴图片后的服务端形态），
 * 文件名遵循 Livewire 编码格式：{random}-meta{base64(原名)}-.{ext}。
 */
function fakeTemporaryUpload(string $name, string $content): TemporaryUploadedFile
{
    $tmpName = \Illuminate\Support\Str::random(30).'-meta'.base64_encode($name).'-.'.\Illuminate\Support\Str::of($name)->afterLast('.')->toString();
    $path = \Livewire\Features\SupportFileUploads\FileUploadConfiguration::path($tmpName, false);
    Storage::disk('tmp-for-tests')->put($path, $content);

    return new TemporaryUploadedFile($tmpName, 'tmp-for-tests');
}

function imageUpload(string $name = 'photo.jpg', int $width = 120, int $height = 80): TemporaryUploadedFile
{
    return fakeTemporaryUpload($name, UploadedFile::fake()->image($name, $width, $height)->get());
}

function articleProvider(Article $article, string $field = 'content'): MediaFileAttachmentProvider
{
    return app(MediaFileAttachmentProvider::class)
        ->attribute($article->getRichContentAttribute($field));
}

it('saves uploaded attachment with content hash and returns the media id', function (): void {
    $user = \Illuminate\Support\Facades\Auth::user();

    $id = app(MediaFileAttachmentProvider::class)->saveUploadedFileAttachment(imageUpload());

    $media = Media::query()->sole();

    expect($id)->toBe((string) $media->id)
        ->and($media->disk)->toBe('local')
        ->and($media->mime)->toBe('image/jpeg')
        ->and($media->original_name)->toBe('photo.jpg')
        ->and($media->width)->toBe(120)
        ->and($media->height)->toBe(80)
        ->and($media->uploader_id)->toBe($user->id)
        ->and($media->ref_count)->toBe(0);

    Storage::disk('cmf-media-local')->assertExists($media->path);
});

it('applies the configured rich content rule path （附件落规则目录）', function (): void {
    config([
        'cmf-media.rules.rich' => ['mimes' => ['image/*'], 'path' => 'rich'],
        'cmf-media.rich_content.rule' => 'rich',
    ]);

    $id = app(MediaFileAttachmentProvider::class)->saveUploadedFileAttachment(imageUpload());

    $media = Media::query()->sole();

    expect($id)->toBe((string) $media->id)
        ->and($media->path)->toStartWith('rich/')
        ->and($media->path)->toBe('rich/'.substr($media->hash, 0, 2).'/'.$media->hash.'.jpg');

    Storage::disk('cmf-media-local')->assertExists($media->path);
});

it('reuses the same media record for duplicate content （去重）', function (): void {
    $provider = app(MediaFileAttachmentProvider::class);

    $first = $provider->saveUploadedFileAttachment(imageUpload('a.jpg'));
    $second = $provider->saveUploadedFileAttachment(imageUpload('b.jpg')); // 同内容不同文件名

    expect($second)->toBe($first)
        ->and(Media::query()->count())->toBe(1);
});

it('restores the soft-deleted record when the same content is uploaded again', function (): void {
    Queue::fake(); // sync 队列下软删会立即执行 DeleteMediaJob 物理删除，fake 后保持软删状态

    $provider = app(MediaFileAttachmentProvider::class);

    $first = $provider->saveUploadedFileAttachment(imageUpload());
    Media::query()->sole()->delete(); // 模拟归零软删

    $second = $provider->saveUploadedFileAttachment(imageUpload());

    expect($second)->toBe($first);
    expect(Media::query()->count())->toBe(1); // withTrashed 外仅一条未软删记录
});

it('rejects disallowed mime detected on the server side', function (): void {
    // 随机字节服务端探测为 application/octet-stream，不在白名单内
    $file = fakeTemporaryUpload('evil.exe', random_bytes(64));

    app(MediaFileAttachmentProvider::class)->saveUploadedFileAttachment($file);
})->throws(ValidationException::class, '不允许的文件类型');

it('rejects oversize files', function (): void {
    config(['cmf-media.max_size' => 10]);

    app(MediaFileAttachmentProvider::class)->saveUploadedFileAttachment(
        fakeTemporaryUpload('big.jpg', str_repeat('x', 11))
    );
})->throws(ValidationException::class, '大小上限');

it('resolves attachment urls by media id', function (): void {
    $media = createMedia(['disk' => 'local']);
    $provider = app(MediaFileAttachmentProvider::class);

    // Storage::fake 的 url 前缀固定为 /storage，断言路径结尾即可
    expect($provider->getFileAttachmentUrl((string) $media->id))
        ->toEndWith('/'.$media->path)
        ->and($provider->getFileAttachmentUrl('999999'))->toBeNull();
});

it('still resolves url for soft-deleted media within the deletion window', function (): void {
    Queue::fake();

    $media = createMedia(['disk' => 'local']);
    $media->delete();

    $provider = app(MediaFileAttachmentProvider::class);

    expect($provider->getFileAttachmentUrl((string) $media->id))
        ->toEndWith('/'.$media->path);
});

it('syncs usages from the content attachment ids on save （引用计数）', function (): void {
    $article = Article::query()->create(['title' => 'demo', 'content' => null]);
    $m1 = createMedia();
    $m2 = createMedia();

    articleProvider($article)->cleanUpFileAttachments([$m1->id, $m2->id]);

    expect($article->mediaUsages()->where('field', 'content')->pluck('media_id')->all())
        ->toEqualCanonicalizing([$m1->id, $m2->id])
        ->and($m1->refresh()->ref_count)->toBe(1)
        ->and($m2->refresh()->ref_count)->toBe(1);

    // 重复同步幂等：不产生重复引用
    articleProvider($article)->cleanUpFileAttachments([(string) $m1->id, (string) $m2->id]);
    expect($article->mediaUsages()->count())->toBe(2)
        ->and($m1->refresh()->ref_count)->toBe(1);
});

it('soft-deletes media and schedules deletion when the reference is removed （清零删除）', function (): void {
    Queue::fake();

    $article = Article::query()->create(['title' => 'demo', 'content' => null]);
    $m1 = createMedia();
    $m2 = createMedia();

    articleProvider($article)->cleanUpFileAttachments([$m1->id, $m2->id]);
    articleProvider($article)->cleanUpFileAttachments([$m2->id]); // 移除 m1

    expect($article->mediaUsages()->pluck('media_id')->all())->toBe([$m2->id]);
    expect(Media::withTrashed()->find($m1->id)->trashed())->toBeTrue()
        ->and(Media::withTrashed()->find($m1->id)->ref_count)->toBe(0)
        ->and($m2->refresh()->ref_count)->toBe(1);

    Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job): bool => $job->mediaId === $m1->id);
});

it('skips cleanup when the host model is not persisted yet', function (): void {
    $article = new Article(['title' => 'draft']);
    $media = createMedia();

    articleProvider($article)->cleanUpFileAttachments([$media->id]);

    expect($media->refresh()->ref_count)->toBe(0);
});

it('requires HasMedia on the host model', function (): void {
    $plain = new class extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'posts';
    };

    $attribute = \Filament\Forms\Components\RichEditor\RichContentAttribute::make($plain, 'content');

    app(MediaFileAttachmentProvider::class)->attribute($attribute);
})->throws(LogicException::class, 'HasMedia');

it('registers the rich content attribute with the media provider', function (): void {
    $article = new Article;

    $attribute = $article->getRichContentAttribute('content');

    expect($attribute)->not->toBeNull()
        ->and($attribute->getFileAttachmentProvider())->toBeInstanceOf(MediaFileAttachmentProvider::class)
        ->and($article->hasRichContentAttribute('content'))->toBeTrue();
});

it('detaches rich content usages when the record is force deleted', function (): void {
    Queue::fake();

    $article = Article::query()->create(['title' => 'demo', 'content' => null]);
    $media = createMedia();

    articleProvider($article)->cleanUpFileAttachments([$media->id]);
    expect($media->refresh()->ref_count)->toBe(1);

    $article->forceDelete();

    expect($media->refresh()->ref_count)->toBe(0);
    expect(\Quansitech\Cmf\Media\Models\MediaUsage::query()->count())->toBe(0);
    Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job): bool => $job->mediaId === $media->id);
});

it('keeps usages when the record is soft deleted', function (): void {
    $article = Article::query()->create(['title' => 'demo', 'content' => null]);
    $media = createMedia();

    articleProvider($article)->cleanUpFileAttachments([$media->id]);

    $article->delete(); // 软删：保留引用，恢复后内容不受影响

    expect($article->mediaUsages()->where('field', 'content')->count())->toBe(1)
        ->and($media->refresh()->ref_count)->toBe(1)
        ->and($media->trashed())->toBeFalse();
});
