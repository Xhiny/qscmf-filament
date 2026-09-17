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
    public function test_文件白名单收窄为仅_xlsx(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        $rules = $action->getFileValidationRules();

        self::assertContains('extensions:xlsx', $rules);
        self::assertNotContains('extensions:csv,txt', $rules);
    }

    public function test_接入方_file_rules_追加的文件规则不被覆写丢失(): void
    {
        // 官方 getFileValidationRules 会把 fileRules() 追加的 fileValidationRules 合并进
        // 最终数组；包内覆写若整体替换 base 而不复刻合并段，接入方自定义文件规则静默
        // 失效（PR #2 评审缺陷 3）。本用例同时是官方合并语义的特征断言，vendor 升级
        // 破坏该行为时在此变红
        $action = XlsxImportAction::make()
            ->importer(FixtureImporter::class)
            ->fileRules(['max:100']);

        $rules = $action->getFileValidationRules();

        self::assertContains('extensions:xlsx', $rules);
        self::assertContains('max:100', $rules);
    }

    public function test_接入方_file_rules_字符串管道语法按竖线拆分(): void
    {
        $action = XlsxImportAction::make()
            ->importer(FixtureImporter::class)
            ->fileRules('file|max:100');

        $rules = $action->getFileValidationRules();

        self::assertContains('file', $rules);
        self::assertContains('max:100', $rules);
    }

    public function test_默认上传体积上限为_20_mb(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        self::assertSame(20 * 1024 * 1024, $action->getMaxFileSize());
        self::assertContains('max:20480', $action->getFileValidationRules());
    }

    public function test_上传体积上限可配置(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class)->maxFileSize(5 * 1024 * 1024);

        self::assertSame(5 * 1024 * 1024, $action->getMaxFileSize());
        self::assertContains('max:5120', $action->getFileValidationRules());
    }

    public function test_合法_xlsx_通过文件校验(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.xlsx', $this->fixtureXlsxBytes());

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_csv_文件被拒绝并提示仅支持_xlsx(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.csv', "id,name\n1,zhang\n");

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
    }

    public function test_伪_xlsx_非_zip_容器被中文拒绝(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', '这不是 xlsx，只是改了后缀的文本');

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('仅支持 xlsx', $validator->errors()->first('file'));
    }

    public function test_zip_容器但非_xlsx_结构被中文拒绝(): void
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

    public function test_重复表头被拒绝(): void
    {
        $builder = XlsxBuilder::make();
        $builder->setText('A1', '姓名')->setText('B1', '姓名')->setText('A2', '张三');

        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('data.xlsx', $this->builderBytes($builder));

        $validator = Validator::make(['file' => $file], ['file' => $this->validatorRules($action)]);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('姓名', $validator->errors()->first('file'));
    }

    public function test_咽喉覆盖_合法_xlsx_输出_ut_f8_cs_v_流(): void
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

    public function test_咽喉覆盖_伪_xlsx_返回_false_由校验层给中文提示(): void
    {
        // 官方 ImportAction 在 validateOnly 规则收集阶段即调用 getUploadedFileStream，
        // 抛异常会在校验执行前把 Livewire 请求炸成 500，故无效 xlsx 须返回 false
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', 'plain text not xlsx');

        self::assertFalse($action->getUploadedFileStream($file));
    }

    public function test_弹窗_file_字段白名单收窄为仅_xlsx(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);

        $fileUpload = $this->fileUploadFromSchema($action);

        self::assertSame(
            [XlsxImportAction::XLSX_MIME_TYPE],
            $fileUpload->getAcceptedFileTypes(),
            '官方 file 字段硬编码 CSV mime 白名单，须收窄为仅 xlsx（服务端 mimetypes 规则动态读取当前白名单 + 浏览器选择器同源）',
        );
    }

    public function test_弹窗_file_字段规则可通过_filament_容器求值(): void
    {
        // Field::rules() 挂载的闭包在 validateOnly 时会先被 Filament evaluate，
        // 必填标量 $attribute 无法注入会抛 BindingResolutionException（上传文件即 500）
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $fileUpload = $this->fileUploadFromSchema($action);

        $rules = $fileUpload->getValidationRules();

        self::assertNotEmpty(array_filter($rules, fn (mixed $rule): bool => $rule instanceof Closure));
    }

    public function test_伪_xlsx_经_弹窗_完整校验链路被拒绝(): void
    {
        // 覆盖 BaseFileUpload 打包闭包 → 内部 Validator → 自定义闭包规则的真实校验链路，
        // 与上传后的 validateOnly 行为等价。打包层仅透出内部首个错误（测试环境 mimetypes
        // 先失败），中文提示文案由直连规则的用例单独覆盖。
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
