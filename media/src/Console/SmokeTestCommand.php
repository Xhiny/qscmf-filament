<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Quansitech\Cmf\Media\Models\Media;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * 真实存储冒烟：put → exists → get → delete 全流程。
 * 云驱动需配置环境变量凭证（TOS_* / OSS_* / COS_*），local 无需凭证，CI 可跳过。
 */
#[AsCommand(name: 'cmf-media:smoke', description: '媒体存储冒烟测试（云驱动需真实 bucket 凭证）')]
class SmokeTestCommand extends Command
{
    protected $signature = 'cmf-media:smoke
        {--disk= : 驱动（tos / oss / cos / local），默认取 cmf-media.default}';

    public function handle(): int
    {
        $driver = (string) ($this->option('disk') ?: config('cmf-media.default', 'tos'));

        if (! in_array($driver, ['tos', 'oss', 'cos', 'local'], true)) {
            $this->components->error("不支持的驱动：{$driver}（可选 tos / oss / cos / local）");

            return self::FAILURE;
        }

        if ($driver !== 'local' && ! config("cmf-media.disks.{$driver}.bucket")) {
            $this->components->error("驱动 {$driver} 未配置 bucket（请检查 cmf-media.disks.{$driver} / 环境变量）");

            return self::FAILURE;
        }

        $diskName = Media::diskName($driver);
        $path = 'cmf-media-smoke/'.Str::random(16).'.txt';
        $content = 'cmf-media smoke test '.now()->toIso8601String();

        try {
            $disk = Storage::disk($diskName);

            $results = [
                $this->check("[{$diskName}] put {$path}", fn (): bool => (bool) $disk->put($path, $content)),
                $this->check("[{$diskName}] exists", fn (): bool => $disk->exists($path)),
                $this->check("[{$diskName}] get 内容一致", fn (): bool => $disk->get($path) === $content),
                $this->check("[{$diskName}] delete", function () use ($disk, $path): bool {
                    $disk->delete($path);

                    return ! $disk->exists($path);
                }),
            ];
        } catch (Throwable $e) {
            $this->components->error("冒烟失败：{$e->getMessage()}");

            return self::FAILURE;
        }

        if (in_array(false, $results, true)) {
            $this->components->error("驱动 {$driver} 冒烟未通过（见上方标红步骤）。");

            return self::FAILURE;
        }

        $this->components->info("驱动 {$driver} 冒烟通过（put → exists → get → delete）。");

        return self::SUCCESS;
    }

    /**
     * 跑一步检查并返回是否通过。
     *
     * Task 组件用 match 严格比较 TaskResult::*->value（int），闭包返回 bool
     * 时失败步骤也会显示 DONE，故这里显式转换；同时把结果回传给 handle()
     * 决定退出码，避免云端失败却以 0 退出。
     */
    private function check(string $description, callable $assertion): bool
    {
        $passed = false;

        $this->components->task(
            $description,
            function () use ($assertion, &$passed): int {
                $passed = (bool) $assertion();

                return $passed ? TaskResult::Success->value : TaskResult::Failure->value;
            },
        );

        return $passed;
    }
}
