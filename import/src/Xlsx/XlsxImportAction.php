<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Closure;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use League\Csv\Reader as CsvReader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * 仅接受 xlsx 的官方导入 Action。
 *
 * 通过覆盖 getUploadedFileStream() 咽喉——官方导入流水线的唯一文件入口
 * （表头嗅探、映射下拉、执行导入、文件校验全部经此读取）——将 xlsx 归一为
 * UTF-8 CSV 流，官方能力（映射 UI、maxRows、队列、失败清单、通知）零丢失。
 *
 * 上传接收层同步收窄：官方 setUp() 给弹窗 file 字段硬编码 CSV mime 白名单
 * （acceptedFileTypes 同时生成服务端 mimetypes 校验与浏览器选择器过滤），
 * 本类覆写 schema() 对该字段改挂 xlsx mime——mimetypes 规则是动态闭包
 * （验证时读取当前白名单），改挂后 xlsx 通过、csv 整单拒绝。
 */
class XlsxImportAction extends ImportAction
{
    public const XLSX_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected int|Closure $maxFileSize = 20971520; // 20MB

    public function schema(array|Closure|null $schema): static
    {
        if (! $schema instanceof Closure) {
            return parent::schema($schema);
        }

        return parent::schema(function () use ($schema): array {
            $components = $this->evaluate($schema);

            foreach ($components as $component) {
                if ($component instanceof FileUpload && $component->getName() === 'file') {
                    $component->acceptedFileTypes([self::XLSX_MIME_TYPE]);
                }
            }

            return $components;
        });
    }

    public function maxFileSize(int|Closure $bytes): static
    {
        $this->maxFileSize = $bytes;

        return $this;
    }

    public function getMaxFileSize(): int
    {
        return $this->evaluate($this->maxFileSize);
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $fileRules = [
            'extensions:xlsx',
            // Laravel 文件 max 单位为 KB；zip 容器防解压放大
            'max:'.intdiv($this->getMaxFileSize(), 1024),
            // Field::rules() 挂载的闭包会先被 Filament 容器求值（官方 ImportAction 同款包裹），
            // 零参外层闭包求值后返回原生 Laravel Validator 闭包 (string $attribute, mixed $value, Closure $fail)
            fn (): Closure => $this->xlsxContainerRule(),
            fn (): Closure => $this->duplicateColumnsRule(),
        ];

        // 复刻官方 fileRules() 合并段：不调 parent::getFileValidationRules()（官方 base
        // 的 extensions:csv,txt 与 xlsx 收窄冲突），但必须保留 fileValidationRules 追加项
        // 的合并语义，否则接入方 fileRules() 自定义文件规则静默失效
        foreach ($this->fileValidationRules as $rules) {
            $rules = $this->evaluate($rules);

            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            $fileRules = [
                ...$fileRules,
                ...$rules,
            ];
        }

        return $fileRules;
    }

    /**
     * 无效 xlsx 返回 false（而非抛异常）：官方 ImportAction 的 afterStateUpdated 与
     * 列映射 Fieldset 闭包在 validateOnly 规则收集阶段即调用本方法，且均按
     * `if (! $csvStream) return` 优雅兜底；抛异常会在校验执行前把 Livewire 请求炸成 500。
     *
     * @return resource | false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        $localPath = $file->getRealPath();
        $tempCopy = null;

        if (! is_string($localPath) || ! is_file($localPath)) {
            // 远端磁盘（如 Livewire 临时上传走 S3）：物化到本地临时文件供 openspout 读取
            $tempCopy = (string) tempnam(sys_get_temp_dir(), 'xlsx-import-');

            $source = $file->readStream();

            if ($source === false) {
                return false;
            }

            $target = fopen($tempCopy, 'w+');

            if ($target === false) {
                return false;
            }

            stream_copy_to_stream($source, $target);
            fclose($target);
            fclose($source);
        }

        try {
            return (new XlsxToCsvConverter)->convert($tempCopy ?? $localPath);
        } catch (InvalidXlsxFileException) {
            return false;
        } finally {
            if ($tempCopy !== null) {
                @unlink($tempCopy);
            }
        }
    }

    private function xlsxContainerRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            if (! $this->hasZipContainerMagic($value)) {
                $fail('仅支持 xlsx 模板文件，请下载导入模板填写后再上传');
            }
        };
    }

    private function hasZipContainerMagic(TemporaryUploadedFile $file): bool
    {
        $magic = false;

        $handle = is_string($file->getRealPath()) && is_file($file->getRealPath())
            ? fopen($file->getRealPath(), 'rb')
            : $file->readStream();

        if ($handle !== false) {
            $magic = fread($handle, 2) === 'PK';
            fclose($handle);
        }

        return $magic;
    }

    /**
     * 官方「重复表头」校验语义不变，读取通道切换为 xlsx 转换流；
     * 伪 xlsx 在此层整单拒绝并给出中文提示。
     */
    private function duplicateColumnsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile) {
                return;
            }

            $csvStream = $this->getUploadedFileStream($value);

            if ($csvStream === false) {
                $fail('文件不是有效的 xlsx 文件，请下载导入模板填写后再上传');

                return;
            }

            $csvReader = CsvReader::from($csvStream);
            $csvReader->setHeaderOffset($this->getHeaderOffset() ?? 0);

            $csvColumns = $csvReader->getHeader();

            $duplicateCsvColumns = [];

            foreach (array_count_values($csvColumns) as $header => $count) {
                if ($count <= 1) {
                    continue;
                }

                $duplicateCsvColumns[] = $header;
            }

            if (empty($duplicateCsvColumns)) {
                return;
            }

            $filledDuplicateCsvColumns = array_filter($duplicateCsvColumns, fn ($value): bool => filled($value));

            $fail(trans_choice('filament-actions::import.modal.form.file.rules.duplicate_columns', count($filledDuplicateCsvColumns), [
                'columns' => implode(', ', $filledDuplicateCsvColumns),
            ]));
        };
    }
}
