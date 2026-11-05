<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Quansitech\Cmf\Import\Tests\Fixtures\FixtureImporter;
use Quansitech\Cmf\Import\Tests\Fixtures\XlsxBuilder;
use Quansitech\Cmf\Import\Tests\TestCase;
use Quansitech\Cmf\Import\Xlsx\XlsxImportAction;
use ZipArchive;

class XlsxImportActionTest extends TestCase
{
    public function test_file_whitelist_narrows_to_xlsx_only(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        $rules = $action->getFileValidationRules();

        self::assertContains('extensions:xlsx', $rules);
        self::assertNotContains('extensions:csv,txt', $rules);
    }

    public function test_consumer_appended_file_rules_are_not_lost(): void
    {
        // 官方会把 fileRules() 追加的 fileValidationRules 合并进最终数组；覆写若整体
        // 替换 base，接入方自定义文件规则静默失效。本用例是官方合并语义的特征断言，
        // vendor 升级破坏该行为时在此变红
        $action = XlsxImportAction::make()
            ->importer(FixtureImporter::class)
            ->fileRules(['max:100']);

        $rules = $action->getFileValidationRules();

        self::assertContains('extensions:xlsx', $rules);
        self::assertContains('max:100', $rules);
    }

    public function test_consumer_pipe_string_file_rules_split_by_pipe(): void
    {
        $action = XlsxImportAction::make()
            ->importer(FixtureImporter::class)
            ->fileRules('file|max:100');

        $rules = $action->getFileValidationRules();

        self::assertContains('file', $rules);
        self::assertContains('max:100', $rules);
    }

    public function test_default_upload_size_limit_is_20_mb(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        self::assertSame(20 * 1024 * 1024, $action->getMaxFileSize());
        self::assertContains('max:20480', $action->getFileValidationRules());
    }

    public function test_upload_size_limit_is_configurable(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class)->maxFileSize(5 * 1024 * 1024);

        self::assertSame(5 * 1024 * 1024, $action->getMaxFileSize());
        self::assertContains('max:5120', $action->getFileValidationRules());
    }

    public function test_valid_xlsx_passes_file_validation(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.xlsx', $this->fixtureXlsxBytes());

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_csv_file_rejected_with_xlsx_only_message(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.csv', "id,name\n1,zhang\n");

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
    }

    public function test_fake_xlsx_without_zip_container_rejected_in_chinese(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', '这不是 xlsx，只是改了后缀的文本');

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('仅支持 xlsx', $validator->errors()->first('file'));
    }

    public function test_zip_container_without_xlsx_structure_rejected_in_chinese(): void
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'not-xlsx-').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'valid zip, not xlsx');
        $zip->close();

        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', (string) file_get_contents($zipPath));

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        unlink($zipPath);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('不是有效的 xlsx', $validator->errors()->first('file'));
    }

    public function test_duplicate_headers_rejected(): void
    {
        $builder = XlsxBuilder::make();
        $builder->setText('A1', '姓名')->setText('B1', '姓名')->setText('A2', '张三');

        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.xlsx', $this->builderBytes($builder));

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('姓名', $validator->errors()->first('file'));
    }

    public function test_overridden_file_stream_outputs_utf8_csv_for_valid_xlsx(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '姓名')
            ->setText('B1', '身份证号')
            ->setText('A2', '张三')
            ->setText('B2', '441302199001011234');

        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.xlsx', $this->builderBytes($builder));

        $stream = $action->getUploadedFileStream($file);

        self::assertIsResource($stream);

        $header = fgetcsv($stream);

        self::assertSame(['姓名', '身份证号'], $header);
        self::assertSame(['张三', '441302199001011234'], fgetcsv($stream));

        fclose($stream);
    }

    public function test_overridden_file_stream_returns_false_for_fake_xlsx(): void
    {
        // 官方 ImportAction 在 validateOnly 规则收集阶段即调用 getUploadedFileStream，
        // 抛异常会在校验执行前把 Livewire 请求炸成 500，故无效 xlsx 须返回 false
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', 'plain text not xlsx');

        self::assertFalse($action->getUploadedFileStream($file));
    }

    public function test_remote_read_failure_returns_false_without_leaving_temp_shell(): void
    {
        $file = $this->createStub(TemporaryUploadedFile::class);
        $file->method('getRealPath')->willReturn('/nonexistent/remote/upload.xlsx');
        $file->method('readStream')->willReturn(false);

        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        $before = count(glob(sys_get_temp_dir().'/xlsx-import-*'));

        self::assertFalse($action->getUploadedFileStream($file));

        $after = count(glob(sys_get_temp_dir().'/xlsx-import-*'));

        self::assertSame($before, $after, 'readStream 失败提前退出后不应在系统临时目录遗留 tempnam 空壳文件');
    }

    public function test_modal_file_field_whitelist_narrows_to_xlsx_only(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        $fileUpload = $this->fileUploadFromSchema($action);

        self::assertSame(
            [XlsxImportAction::XLSX_MIME_TYPE],
            $fileUpload->getAcceptedFileTypes(),
            '官方 file 字段硬编码 CSV mime 白名单，须收窄为仅 xlsx（服务端 mimetypes 规则动态读取当前白名单 + 浏览器选择器同源）',
        );
    }

    public function test_modal_file_field_rules_evaluable_via_filament_container(): void
    {
        // Field::rules() 挂载的闭包在 validateOnly 时会先被 Filament evaluate，
        // 必填标量 $attribute 无法注入会抛 BindingResolutionException（上传文件即 500）
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $fileUpload = $this->fileUploadFromSchema($action);

        $rules = $fileUpload->getValidationRules();

        self::assertNotEmpty(array_filter($rules, fn (mixed $rule): bool => $rule instanceof Closure));
    }

    public function test_fake_xlsx_rejected_through_modal_full_validation_chain(): void
    {
        // 覆盖 BaseFileUpload 打包闭包 → 内部 Validator 的真实校验链路，与上传后的
        // validateOnly 等价；打包层仅透出首个错误，中文文案由直连规则用例覆盖
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $rules = $this->fileUploadFromSchema($action)->getValidationRules();
        $file = $this->makeFakeUpload('fake.xlsx', '这不是 xlsx，只是改了后缀的文本');

        $validator = Validator::make(['file' => [$file]], ['file' => $rules]);

        self::assertTrue($validator->fails(), $validator->errors()->toJson());
    }

    private function fileUploadFromSchema(XlsxImportAction $action): FileUpload
    {
        $schema = $action->getSchema(Schema::make());

        self::assertNotNull($schema);

        $fileUpload = collect($schema->getComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof FileUpload && $component->getName() === 'file');

        self::assertNotNull($fileUpload, '官方导入 schema 须含 file 字段');

        return $fileUpload;
    }

    /**
     * Laravel Validator 直连视角：Filament 包裹闭包还原为原生规则闭包后再交给 Validator。
     */
    private function validatorRules(XlsxImportAction $action): array
    {
        return array_map(
            fn (mixed $rule): mixed => $rule instanceof Closure ? $rule() : $rule,
            $action->getFileValidationRules(),
        );
    }

    private function makeFakeUpload(string $originalName, string $contents): TemporaryUploadedFile
    {
        $disk = Storage::fake('local');
        $disk->put('livewire-tmp/'.$originalName, $contents);

        return new TemporaryUploadedFile($originalName, 'local');
    }

    private function builderBytes(XlsxBuilder $builder): string
    {
        return (string) file_get_contents($builder->toTempFile());
    }

    private function fixtureXlsxBytes(): string
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '姓名')
            ->setText('B1', '身份证号')
            ->setText('C1', '性别')
            ->setText('A2', '张三')
            ->setText('B2', '441302199001011234')
            ->setText('C2', '男');

        return $this->builderBytes($builder);
    }
}
