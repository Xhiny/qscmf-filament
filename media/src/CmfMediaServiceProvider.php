<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Quansitech\Cmf\Core\Cmf;
use Quansitech\Cmf\Media\Console\PruneOrphanMediaCommand;
use Quansitech\Cmf\Media\Console\SmokeTestCommand;
use Quansitech\Cmf\Media\Contracts\ObjectInspector;
use Quansitech\Cmf\Media\Events\MediaUsageAttached;
use Quansitech\Cmf\Media\Events\MediaUsageDetached;
use Quansitech\Cmf\Media\Flysystem\OssFilesystemAdapter;
use Quansitech\Cmf\Media\Listeners\UpdateMediaRefCount;
use Quansitech\Cmf\Media\Models\AuditableMedia;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\StorageObjectInspector;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CmfMediaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cmf-media')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasCommands([SmokeTestCommand::class, PruneOrphanMediaCommand::class]);
    }

    public function packageRegistered(): void
    {
        Cmf::registerPlugin(MediaPlugin::class);

        $this->app->bind(ObjectInspector::class, StorageObjectInspector::class);
    }

    public function packageBooted(): void
    {
        $this->registerModuleAssets();
        $this->configureAuditModel();
        $this->registerDisks();
        $this->registerEventListeners();
        $this->registerFilamentAssets();
        $this->registerOrphanCleanupSchedule();

        Gate::policy(config('cmf-media.model'), config('cmf-media.policy'));

        // 向 Shield 登记本模块的权限点；宿主已在 config/filament-shield.php
        // 配置同名条目时以宿主为准
        $manage = config('filament-shield.resources.manage', []);
        $manage[config('cmf-media.resource')] ??= config('cmf-media.permissions');
        config()->set('filament-shield.resources.manage', $manage);
    }

    /**
     * 模块配置 / 迁移 / 前端资源挂到 cmf 系列 tag，由 cmf:install 发布
     * （不覆盖项目已有文件）。迁移同时 loadMigrationsFrom：发布到宿主后
     * 文件名一致，迁移器按名称去重，不会重复建表。
     */
    protected function registerModuleAssets(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cmf-media.php' => config_path('cmf-media.php'),
        ], 'cmf-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cmf-media-migrations');

        $this->publishes([
            __DIR__.'/../resources/js' => public_path('vendor/cmf-media'),
            __DIR__.'/../resources/css' => public_path('vendor/cmf-media/css'),
        ], 'cmf-media-assets');
    }

    /**
     * 审计集成（可选）：config('cmf-media.audit') 开启时切换为可审计模型，
     * 需宿主已安装 owen-it/laravel-auditing。
     */
    protected function configureAuditModel(): void
    {
        if (! config('cmf-media.audit', false)) {
            return;
        }

        if (! interface_exists('OwenIt\Auditing\Contracts\Auditable')) {
            throw new RuntimeException(
                'cmf-media.audit 已开启，但未安装 owen-it/laravel-auditing；'
                .'请安装 quansitech/cmf-module-auditing（或 owen-it/laravel-auditing），或关闭该配置。'
            );
        }

        if (config('cmf-media.model') === Media::class) {
            config()->set('cmf-media.model', AuditableMedia::class);
        }
    }

    /**
     * 按 config 注册 Flysystem disk（cmf-media-tos / -oss / -cos / -local）：
     * 云驱动凭证齐全才注册；adapter 类（composer suggest）未安装时，
     * 在 disk 首次解析时经 class_exists 检测抛出明确异常。
     * local 为 Laravel 原生驱动，始终注册（root 默认 public/cmf-media）。
     */
    protected function registerDisks(): void
    {
        // local：服务器本地磁盘，无 adapter 依赖
        /** @var array<string, mixed> $local */
        $local = config('cmf-media.disks.local', []);
        config()->set('filesystems.disks.cmf-media-local', [
            'driver' => 'local',
            'root' => $local['root'] ?? public_path('cmf-media'),
            'url' => $local['url'] ?? '/cmf-media',
            'visibility' => 'public',
            'throw' => false,
        ]);
        // TOS：S3 兼容签名体系（火山引擎）
        Storage::extend('cmf-media-tos', function (mixed $app, array $config): FilesystemContract {
            if (! class_exists('League\Flysystem\AwsS3V3\AwsS3V3Adapter') || ! class_exists('Aws\S3\S3Client')) {
                throw new RuntimeException(
                    'TOS 驱动需要 S3 兼容适配器，请执行：composer require league/flysystem-aws-s3-v3'
                );
            }

            /** @var string $region */
            $region = $config['region'] ?? 'cn-beijing';

            $client = new \Aws\S3\S3Client([
                'credentials' => [
                    'key' => $config['key'] ?? '',
                    'secret' => $config['secret'] ?? '',
                ],
                'region' => $region,
                'version' => 'latest',
                'endpoint' => 'https://'.($config['endpoint'] ?? "tos-s3-{$region}.volces.com"),
                'use_path_style_endpoint' => false,
            ]);

            $adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter(
                $client,
                (string) ($config['bucket'] ?? ''),
                '',
                new \League\Flysystem\AwsS3V3\PortableVisibilityConverter(),
            );

            // 必须用 Laravel 的 S3 专用包装类而非通用 FilesystemAdapter：
            // Laravel 的 url()/temporaryUrl() 只认 getUrl()/getTemporaryUrl() 方法，
            // 通用包装类 + League 适配器会抛 "This driver does not support retrieving URLs"。
            return new \Illuminate\Filesystem\AwsS3V3Adapter(
                new Filesystem($adapter),
                $adapter,
                $config,
                $client,
            );
        });

        // OSS：阿里云（xxtime/flysystem-aliyun-oss）
        Storage::extend('cmf-media-oss', function (mixed $app, array $config): FilesystemContract {
            if (! class_exists('Xxtime\Flysystem\Aliyun\OssAdapter')) {
                throw new RuntimeException(
                    'OSS 驱动需要 Flysystem 适配器，请执行：composer require xxtime/flysystem-aliyun-oss'
                );
            }

            $adapter = new \Xxtime\Flysystem\Aliyun\OssAdapter([
                'accessId' => $config['key'] ?? '',
                'accessSecret' => $config['secret'] ?? '',
                'bucket' => $config['bucket'] ?? '',
                'endpoint' => $config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com',
            ]);

            // OssFilesystemAdapter 补齐 url()（xxtime 适配器无 Laravel 识别的 getUrl()）
            return new OssFilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        // COS：腾讯云（overtrue/flysystem-cos）
        Storage::extend('cmf-media-cos', function (mixed $app, array $config): FilesystemContract {
            if (! class_exists('Overtrue\Flysystem\Cos\CosAdapter')) {
                throw new RuntimeException(
                    'COS 驱动需要 Flysystem 适配器，请执行：composer require overtrue/flysystem-cos'
                );
            }

            /** @var string $bucket 形如 example-1250000000，末段为 appid */
            $bucket = (string) ($config['bucket'] ?? '');
            $appId = str_contains($bucket, '-') ? substr($bucket, (int) strrpos($bucket, '-') + 1) : '';

            $adapter = new \Overtrue\Flysystem\Cos\CosAdapter([
                'bucket' => $bucket,
                'app_id' => $appId,
                'region' => $config['region'] ?? 'ap-guangzhou',
                'secret_id' => $config['secret_id'] ?? '',
                'secret_key' => $config['secret_key'] ?? '',
            ]);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        foreach (['tos', 'oss', 'cos'] as $driver) {
            /** @var array<string, mixed> $disk */
            $disk = config("cmf-media.disks.{$driver}", []);

            if (filled($disk['bucket'] ?? null)) {
                config()->set("filesystems.disks.cmf-media-{$driver}", [
                    'driver' => "cmf-media-{$driver}",
                    ...$disk,
                ]);
            }
        }
    }

    /**
     * 孤儿媒体兜底清理：注册每日调度（需宿主 crontab 配置 schedule:run）。
     * auto_delete 开关在每次运行前判断（when 闭包运行期读取配置），
     * 关闭时该次调度跳过。超时零引用记录由命令软删并排期清理云端对象。
     */
    protected function registerOrphanCleanupSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PruneOrphanMediaCommand::class)
                ->daily()
                ->when(fn (): bool => (bool) config('cmf-media.auto_delete', true));
        });
    }

    protected function registerEventListeners(): void
    {
        Event::listen(MediaUsageAttached::class, [UpdateMediaRefCount::class, 'handleAttached']);
        Event::listen(MediaUsageDetached::class, [UpdateMediaRefCount::class, 'handleDetached']);
    }

    protected function registerFilamentAssets(): void
    {
        FilamentAsset::register([
            Css::make('cmf-media-cropper', __DIR__.'/../resources/css/cropper.min.css'),
            Css::make('cmf-media-crop', __DIR__.'/../resources/css/crop.css'),
            Js::make('cmf-media-spark-md5', __DIR__.'/../resources/js/spark-md5.min.js'),
            Js::make('cmf-media-hash-worker', __DIR__.'/../resources/js/hash.worker.js'),
            Js::make('cmf-media-cropper', __DIR__.'/../resources/js/cropper.min.js'),
            Js::make('cmf-media-crop', __DIR__.'/../resources/js/crop.js'),
            Js::make('cmf-media-direct-upload', __DIR__.'/../resources/js/direct-upload.js'),
        ], 'quansitech/cmf-module-media');
    }
}
