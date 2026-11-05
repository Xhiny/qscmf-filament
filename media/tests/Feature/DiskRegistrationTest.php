<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Quansitech\Cmf\Media\Models\Media;

it('registers flysystem disks for configured drivers', function (): void {
    expect(config('filesystems.disks.cmf-media-tos.driver'))->toBe('cmf-media-tos')
        ->and(config('filesystems.disks.cmf-media-oss.driver'))->toBe('cmf-media-oss')
        ->and(config('filesystems.disks.cmf-media-cos.driver'))->toBe('cmf-media-cos');
});

it('resolves tos disk to the S3 compatible adapter', function (): void {
    $adapter = Storage::disk('cmf-media-tos')->getAdapter();

    expect($adapter)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and(Media::diskName('tos'))->toBe('cmf-media-tos');
});

it('throws a clear exception when the oss adapter package is missing', function (): void {
    Storage::disk('cmf-media-oss');
})->throws(RuntimeException::class, 'composer require xxtime/flysystem-aliyun-oss');

it('throws a clear exception when the cos adapter package is missing', function (): void {
    // 回归套件（CosDriverHostTest）需要真实适配器，已将 overtrue/flysystem-cos
    // 列入 require-dev；缺包分支在宿主未装此包的环境下仍然生效，此处仅在
    // 开发环境装了包时跳过，避免假失败。
    if (class_exists(\Overtrue\Flysystem\Cos\CosAdapter::class)) {
        $this->markTestSkipped('dev 环境已装 overtrue/flysystem-cos，缺包分支仅在宿主未装时生效');
    }

    Storage::disk('cmf-media-cos');
})->throws(RuntimeException::class, 'composer require overtrue/flysystem-cos');

it('smoke command fails clearly without real bucket credentials', function (): void {
    config()->set('cmf-media.disks.tos.bucket', null);

    $this->artisan('cmf-media:smoke', ['--disk' => 'tos'])->assertFailed();
});

it('smoke command rejects unknown driver', function (): void {
    $this->artisan('cmf-media:smoke', ['--disk' => 'unknown'])->assertFailed();
});
