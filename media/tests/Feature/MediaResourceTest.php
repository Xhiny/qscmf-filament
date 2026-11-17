<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;
use Quansitech\Cmf\Media\Filament\Resources\Media\Pages\ListMedia;
use Quansitech\Cmf\Media\Filament\Resources\Media\Pages\ViewMedia;
use Quansitech\Cmf\Media\Filament\Resources\Media\RelationManagers\UsagesRelationManager;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\Post;

it('renders the media list page', function (): void {
    actingAsTestUser();

    $records = collect(range(1, 3))->map(fn (): \Quansitech\Cmf\Media\Models\Media => createMedia());

    Livewire::test(ListMedia::class)
        ->assertOk()
        ->assertCanSeeTableRecords($records->all());
});

it('filters by disk', function (): void {
    actingAsTestUser();

    $tos = createMedia(['disk' => 'tos']);
    $oss = createMedia(['disk' => 'oss']);

    Livewire::test(ListMedia::class)
        ->filterTable('disk', 'oss')
        ->assertCanSeeTableRecords([$oss])
        ->assertCanNotSeeTableRecords([$tos]);
});

it('filters by reference status', function (): void {
    actingAsTestUser();

    $referenced = createMedia();
    $orphan = createMedia();
    Post::create(['title' => 'x'])->attachMedia($referenced->id, 'cover');

    Livewire::test(ListMedia::class)
        ->filterTable('referenced', true)
        ->assertCanSeeTableRecords([$referenced])
        ->assertCanNotSeeTableRecords([$orphan]);
});

it('sorts by ref_count', function (): void {
    actingAsTestUser();

    $low = createMedia();
    $high = createMedia();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($high->id, 'cover');
    $post->attachMedia($high->id, 'gallery');

    Livewire::test(ListMedia::class)
        ->sortTable('ref_count', 'desc')
        ->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

it('hides the delete action for referenced media（被引用不出删除按钮）', function (): void {
    actingAsTestUser();

    $referenced = createMedia();
    Post::create(['title' => 'x'])->attachMedia($referenced->id, 'cover');

    $orphan = createMedia();

    Livewire::test(ListMedia::class)
        ->assertTableActionHidden('delete', $referenced)
        ->assertTableActionVisible('delete', $orphan);
});

it('hides the delete action on the view page for referenced media（详情页同步）', function (): void {
    actingAsTestUser();

    $referenced = createMedia();
    Post::create(['title' => 'x'])->attachMedia($referenced->id, 'cover');

    $orphan = createMedia();

    Livewire::test(ViewMedia::class, ['record' => $referenced->getRouteKey()])
        ->assertActionHidden('delete');

    Livewire::test(ViewMedia::class, ['record' => $orphan->getRouteKey()])
        ->assertActionVisible('delete');
});

it('renders the view page with usage details', function (): void {
    actingAsTestUser();

    $media = createMedia();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($media->id, 'cover');
    $post->attachMedia($media->id, 'gallery');

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk();

    $usages = $media->usages()->get();

    // 引用明细单次查询加载（无 N+1）
    $usageQueries = 0;
    DB::listen(function ($query) use (&$usageQueries): void {
        if (str_contains($query->sql, 'cmf_media_usages')) {
            $usageQueries++;
        }
    });

    Livewire::test(UsagesRelationManager::class, [
        'ownerRecord' => $media,
        'pageClass' => ViewMedia::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($usages);

    expect($usageQueries)->toBeLessThanOrEqual(1);
});

it('eager loads the uploader column without N+1', function (): void {
    $user = actingAsTestUser();

    collect(range(1, 3))->each(fn () => createMedia(['uploader_id' => $user->id]));

    $userQueries = 0;
    DB::listen(function ($query) use (&$userQueries): void {
        if (str_contains($query->sql, '"users"')) {
            $userQueries++;
        }
    });

    Livewire::test(ListMedia::class)->assertOk();

    expect($userQueries)->toBeLessThanOrEqual(1);
});

it('exposes the resource url under the admin panel', function (): void {
    actingAsTestUser();

    $this->get(MediaResource::getUrl('index'))->assertOk();
});

it('renders an image preview linking to the original on the view page', function (): void {
    actingAsTestUser();

    $media = createMedia(); // 默认 image/jpeg

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('点击查看原图')
        ->assertSeeHtml('<img');
});

it('renders a video player for video media on the view page', function (): void {
    actingAsTestUser();

    $media = createMedia(['mime' => 'video/mp4', 'ext' => 'mp4', 'original_name' => 'clip.mp4']);

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('<video controls')
        ->assertSeeHtml('type="video/mp4"');
});

it('renders an audio player for audio media on the view page', function (): void {
    actingAsTestUser();

    $media = createMedia(['mime' => 'audio/mpeg', 'ext' => 'mp3', 'original_name' => 'song.mp3']);

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('<audio controls');
});

it('renders a download link for other file types on the view page', function (): void {
    actingAsTestUser();

    $media = createMedia(['mime' => 'application/pdf', 'ext' => 'pdf', 'original_name' => 'doc.pdf']);

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk()
        ->assertSeeHtml('下载文件（doc.pdf）');
});

it('renders a placeholder when the media url cannot be generated', function (): void {
    actingAsTestUser();

    $media = createMedia(['disk' => 'ghost']); // disk 未注册 → url() 为 null

    Livewire::test(ViewMedia::class, ['record' => $media->getRouteKey()])
        ->assertOk()
        ->assertSee('无法生成访问链接');
});

it('hides soft-deleted media from the list（引用归零软删后不展示）', function (): void {
    \Illuminate\Support\Facades\Queue::fake();
    actingAsTestUser();

    $media = createMedia();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover'); // 归零 → 软删

    expect($media->fresh()->trashed())->toBeTrue();

    Livewire::test(ListMedia::class)
        ->assertCanNotSeeTableRecords([$media]);
});

it('keeps zero-ref media listed and deletable when auto delete is off（走队列清理）', function (): void {
    \Illuminate\Support\Facades\Queue::fake();
    actingAsTestUser();
    config()->set('cmf-media.auto_delete', false);

    $media = createMedia();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover'); // 归零但不软删

    expect($media->fresh()->trashed())->toBeFalse();

    // 列表仍可见，删除按钮可用，点击后软删并排期队列清理
    Livewire::test(ListMedia::class)
        ->assertCanSeeTableRecords([$media])
        ->assertTableActionEnabled('delete', $media)
        ->callTableAction('delete', $media);

    expect($media->fresh()->trashed())->toBeTrue();
    \Illuminate\Support\Facades\Queue::assertPushed(
        \Quansitech\Cmf\Media\Jobs\DeleteMediaJob::class,
        fn (\Quansitech\Cmf\Media\Jobs\DeleteMediaJob $job): bool => $job->mediaId === $media->id,
    );
});
