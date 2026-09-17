<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use Closure;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Import\Tests\Fixtures\FixtureImporter;
use Quansitech\Cmf\Import\Tests\TestCase;
use Quansitech\Cmf\Import\Xlsx\XlsxFailedRowsDownloader;
use Quansitech\Cmf\Import\Xlsx\XlsxImporter;

class XlsxImporterTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    public function test_所有已映射列自动附加可疑值检测规则(): void
    {
        $importer = $this->makeImporter(['name' => '姓名', 'id_card' => '身份证号', 'gender' => '性别']);

        $rules = $importer->getValidationRules();

        foreach (['name', 'id_card', 'gender'] as $columnName) {
            $closureRules = array_filter($rules[$columnName], fn ($rule): bool => $rule instanceof Closure);

            self::assertNotEmpty($closureRules, "列 [{$columnName}] 应附加可疑值检测规则");
        }
    }

    public function test_未映射列不生成校验规则(): void
    {
        $importer = $this->makeImporter(['name' => '姓名']);

        $rules = $importer->getValidationRules();

        self::assertArrayHasKey('name', $rules);
        self::assertArrayNotHasKey('id_card', $rules);
        self::assertArrayNotHasKey('gender', $rules);
    }

    public function test_科学计数法透传值行级失败并给出中文原因(): void
    {
        $importer = $this->makeImporter(['name' => '姓名', 'id_card' => '身份证号', 'gender' => '性别']);

        try {
            Validator::validate(
                $importer->getData(),
                $importer->getValidationRules(),
                $importer->getValidationMessages(),
                $importer->getValidationAttributes(),
            );

            self::fail('应抛出 ValidationException');
        } catch (ValidationException $exception) {
            $idCardErrors = $exception->errors()['id_card'] ?? [];
            $reasons = implode(' ', $idCardErrors);

            self::assertStringContainsString('设为文本格式后用模板重新填写', $reasons);
        }
    }

    public function test_文本形态_18_位身份证正常通过检测(): void
    {
        $importerClass = FixtureImporter::class;
        $import = new Import;
        $import->importer = $importerClass;

        $importer = new $importerClass($import, ['name' => '姓名', 'id_card' => '身份证号', 'gender' => '性别'], []);

        $importer->__invoke([
            '姓名' => '张三',
            '身份证号' => '441302199001011234',
            '性别' => '男',
        ]);

        self::assertEquals('441302199001011234', $importer->getData()['id_card']);

        // 完整走通 __invoke（校验 + 落库）
        self::assertDatabaseHas('fixture_members', [
            'name' => '张三',
            'id_card' => '441302199001011234',
            'gender' => '男',
        ]);
    }

    public function test_失败清单下载器默认覆写为_xlsx(): void
    {
        self::assertInstanceOf(
            XlsxFailedRowsDownloader::class,
            FixtureImporter::getFailedRowsDownloader(),
        );
    }

    private function makeImporter(array $columnMap): XlsxImporter
    {
        $importerClass = FixtureImporter::class;

        $import = new Import;
        $import->importer = $importerClass;

        $importer = new $importerClass($import, $columnMap, []);

        // __invoke 会走完整流水线（校验失败即抛异常），这里仅需 remap + cast 后的状态
        try {
            $importer->__invoke([
                '姓名' => '张三',
                '身份证号' => '4.41302E+17',
                '性别' => '男',
            ]);
        } catch (ValidationException) {
        }

        return $importer;
    }
}
