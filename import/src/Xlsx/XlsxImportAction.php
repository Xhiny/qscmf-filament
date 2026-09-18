<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Closure;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use League\Csv\Reader as CsvReader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * 仅接受 xlsx 的官方导入 Action：覆盖 getUploadedFileStream() 咽喉——官方导入
 * 流水线（嗅探、映射、执行、校验）的唯一文件入口——把 xlsx 归一为 UTF-8 CSV 流，
 * 并把弹窗 file 字段的 mime 白名单收窄为 xlsx。
 */
class XlsxImportAction extends ImportAction
{
    public const XLSX_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected int|Closure $maxFileSize = 20971520; // 20MB

    /**
     * mime 收窄仅覆盖 Closure 形态；传数组时官方 CSV 白名单原样保留，xlsx 会被
     * 服务端 mimetypes 校验拒绝，须自行对 file 字段调用 acceptedFileTypes 收窄。
     */
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
            // Laravel max 单位为 KB
            'max:'.intdiv($this->getMaxFileSize(), 1024),
            // 零参外层闭包经 Filament 容器求值后返回原生校验闭包（直挂会被注入标量参数）
            fn (): Closure => $this->xlsxContainerRule(),
            fn (): Closure => $this->duplicateColumnsRule(),
        ];

        // 不调 parent（官方 base 含 extensions:csv,txt，与 xlsx 收窄冲突），但须保留
        // fileValidationRules 追加项的合并语义，否则接入方自定义文件规则静默失效
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
     * 无效 xlsx 返回 false 而非抛异常：官方消费点按 `if (! $csvStream) return` 兜底，
     * 抛异常会在校验执行前把 Livewire 请求炸成 500。
     *
     * @return resource | false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        $localPath = $file->getRealPath();
        $tempCopy = null;

        try {
            if (! is_string($localPath) || ! is_file($localPath)) {
                // 远端磁盘（如 Livewire 临时上传走 S3）：物化到本地临时文件供 openspout 读取
                $tempCopy = (string) tempnam(sys_get_temp_dir(), 'xlsx-import-');

                $source = $file->readStream();

                if ($source === false) {
                    return false;
                }

                $target = fopen($tempCopy, 'w+');

                if ($target === false) {
                    fclose($source);

                    return false;
                }

                stream_copy_to_stream($source, $target);
                fclose($target);
                fclose($source);
            }

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

    /** 官方「重复表头」校验语义不变，仅读取通道切换为 xlsx 转换流。 */
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
