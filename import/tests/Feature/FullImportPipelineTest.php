<?php

namespace Quansitech\Cmf\Import\Tests\Feature;

use Closure;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Support\ChunkIterator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Quansitech\Cmf\Import\Tests\Fixtures\FixtureImporter;
use Quansitech\Cmf\Import\Tests\Fixtures\Models\FixtureMember;
use Quansitech\Cmf\Import\Tests\Fixtures\Models\FixtureUser;
use Quansitech\Cmf\Import\Tests\Fixtures\ThrowingSourceImporter;
use Quansitech\Cmf\Import\Tests\Fixtures\XlsxBuilder;
use Quansitech\Cmf\Import\Tests\TestCase;
use Quansitech\Cmf\Import\Xlsx\XlsxImportAction;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * xlsx 全链路三态 Feature 测试：
 * 经 XlsxImportAction 咽喉 → xlsx→CSV 转换 → 官方 ImportCsv job → Importer → 落库 /
 * FailedImportRow。执行路径与官方 ImportAction 的 action 闭包一致。
 */
class FullImportPipelineTest extends TestCase
{
    private FixtureUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = FixtureUser::create([
            'name' => '运营',
            'email' => 'operator@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    public function test_three_way_all_rows_succeed(): void
    {
        $builder = $this->fixtureBuilder();
        $builder->setText('A3', '李四')->setText('B3', '441302199001012345')->setText('C3', '女');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        self::assertSame(2, $import->total_rows);
        self::assertSame(2, $import->refresh()->successful_rows);
        self::assertSame(0, $import->getFailedRowsCount());
        self::assertSame(2, FixtureMember::count());
        self::assertSame('441302199001011234', FixtureMember::find(1)->id_card);
    }

    public function test_three_way_partial_failures_go_to_row_level_channel(): void
    {
        $builder = $this->fixtureBuilder();
        // 身份证号为数值单元格 → Excel 已截断 → 科学计数法透传 → 行级拒绝
        $builder->setText('A3', '李四')->setText('B3', '12345')->setText('C3', '女');
        $builder->setText('A4', '王五');
        $builder->setNumeric('B4', (float) '441302199001011234');
        $builder->setText('C4', '男');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        self::assertSame(3, $import->total_rows);
        self::assertSame(1, $import->refresh()->successful_rows);
        self::assertSame(2, $import->getFailedRowsCount());
        self::assertSame(1, FixtureMember::count());

        $reasons = $import->failedRows()->pluck('validation_error')->all();

        self::assertStringContainsString('设为文本格式后用模板重新填写', (string) $reasons[1]);
    }

    public function test_three_way_total_rejection_fake_xlsx_rejected_at_upload_validation(): void
    {
        $action = XlsxImportAction::make()->importer(FixtureImporter::class);
        $file = $this->makeFakeUpload('fake.xlsx', '不是 xlsx 的文本内容');

        // Filament 包裹闭包还原为原生规则闭包后再交给 Validator（与弹窗校验等价）
        $rules = array_map(
            fn (mixed $rule): mixed => $rule instanceof Closure ? $rule() : $rule,
            $action->getFileValidationRules(),
        );

        $validator = Validator::make(['file' => $file], ['file' => $rules]);

        self::assertTrue($validator->fails());
        self::assertSame(0, FixtureMember::count());
    }

    public function test_failed_rows_xlsx_contains_original_data_and_joined_reasons_column(): void
    {
        $builder = $this->fixtureBuilder();
        $builder->setText('A3', '李四')->setText('B3', '12345');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        self::assertSame(1, $import->getFailedRowsCount());

        $response = FixtureImporter::getFailedRowsDownloader()($import);

        $bytes = $this->captureResponseContent($response);
        $rows = $this->readXlsxRows($bytes);

        self::assertSame(['姓名', '身份证号', '性别', '失败原因'], $rows[0]);

        // 失败原因 = 该行全部原因拼接（官方以空格连接），原行数据完整保留
        $reason = (string) $rows[1][3];

        self::assertStringContainsString('invalid', $reason);
        self::assertStringContainsString('required', $reason);
        // 空字符串单元格经 phpspreadsheet toArray() 读回为 null
        self::assertSame(['李四', '12345', null, $reason], $rows[1]);
    }

    public function test_retransmission_keeps_single_failure_reason_column(): void
    {
        // 上一轮失败清单重传产生的失败行：快照原始表头已含「失败原因」键。
        // 下载器不去重会把清单写成双列「失败原因」，重传必触发重复列标题文件级校验
        $import = new Import;
        $import->user()->associate($this->user);
        $import->file_name = 'data.xlsx';
        $import->file_path = 'unused';
        $import->importer = FixtureImporter::class;
        $import->total_rows = 1;
        $import->save();

        $import->failedRows()->create([
            'data' => [
                '姓名' => '李四',
                '身份证号' => '12345',
                '性别' => '',
                '失败原因' => '身份证号格式不正确（上一轮）',
            ],
            'validation_error' => '身份证号格式不正确（本轮）',
        ]);

        $bytes = $this->captureResponseContent(FixtureImporter::getFailedRowsDownloader()($import));
        $rows = $this->readXlsxRows($bytes);

        self::assertSame(['姓名', '身份证号', '性别', '失败原因'], $rows[0]);
        self::assertSame(
            1,
            count(array_keys($rows[0], '失败原因')),
            '重传链路下载的清单「失败原因」恒为一列',
        );
        // 末列取当前轮 validation_error，快照中的旧「失败原因」键被剔除且不错位
        // （空字符串单元格经 phpspreadsheet toArray() 读回为 null）
        self::assertSame(['李四', '12345', null, '身份证号格式不正确（本轮）'], $rows[1]);
    }

    public function test_failed_rows_sheet_keeps_template_level_constraints(): void
    {
        $builder = $this->fixtureBuilder();
        $builder->setText('A3', '李四')->setText('B3', '12345');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        self::assertSame(1, $import->getFailedRowsCount());

        $bytes = $this->captureResponseContent(FixtureImporter::getFailedRowsDownloader()($import));

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-failed-assert-');
        file_put_contents($path, $bytes);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $optionsSheet = $spreadsheet->getSheetByName('_options');

        // 文本锁定与模板同口径
        self::assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        self::assertSame('@', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        self::assertNotSame('@', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());

        $length = $sheet->getDataValidation('B2');

        self::assertSame('textLength', $length->getType());
        self::assertSame('between', $length->getOperator());
        self::assertSame('18', $length->getFormula1());
        self::assertSame('18', $length->getFormula2());

        // 选项为清单生成时点的快照（非实时查询）
        self::assertNotNull($optionsSheet);
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $optionsSheet->getSheetState());
        self::assertSame('男', $optionsSheet->getCell('A1')->getValue());
        self::assertSame('女', $optionsSheet->getCell('A2')->getValue());

        $dropdown = $sheet->getDataValidation('C2');

        self::assertSame('list', $dropdown->getType());
        self::assertSame('_options!$A$1:$A$2', $dropdown->getFormula1());

        self::assertSame('none', $sheet->getDataValidation('D2')->getType());

        unlink($path);
    }

    public function test_failed_rows_dropdown_xml_must_not_suppress_arrow(): void
    {
        $builder = $this->fixtureBuilder();
        $builder->setText('A3', '李四')->setText('B3', '12345');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        $bytes = $this->captureResponseContent(FixtureImporter::getFailedRowsDownloader()($import));

        // 读回断言测不到落盘属性（读回默认值掩盖真实 XML），须直查 worksheets 原文；
        // OOXML showDropDown="1" 语义为「抑制下拉箭头」，真机表现为下拉不可见
        self::assertSame([], $this->dropdownSuppressions($bytes));
    }

    public function test_failed_rows_data_cells_persisted_as_string_type(): void
    {
        $builder = $this->fixtureBuilder();
        // 姓名缺失（required 失败）而身份证合法 → 18 位数字字符串完整进清单
        $builder->setText('B3', '441302199001019999');

        $import = $this->importRows($this->makeFakeUpload('data.xlsx', $this->bytes($builder)));

        self::assertSame(1, $import->getFailedRowsCount());

        $bytes = $this->captureResponseContent(FixtureImporter::getFailedRowsDownloader()($import));
        $this->assertCellPersistedAsString($bytes, 'B2', '数值绑定会让长数字变科学计数法且重传触发检测死循环');

        // 行为级兜底：读回值保持完整字符串
        $rows = $this->readXlsxRows($bytes);

        self::assertSame('441302199001019999', $rows[1][1]);
    }

    public function test_unmatched_headers_degrade_without_data_loss(): void
    {
        $builder = XlsxBuilder::make();
        // 上传文件表头与导入列 label/name 均不同（导入时经手动映射完成的场景），
        // 仅「性别」可按 label 匹配
        $builder
            ->setText('A1', '客户姓名')->setText('B1', '证件号码')->setText('C1', '性别')
            ->setText('A2', '张三')->setText('B2', '441302199001011234')->setText('C2', '男')
            ->setText('A3', '李四')->setText('B3', '12345');

        $import = $this->importRows(
            $this->makeFakeUpload('data.xlsx', $this->bytes($builder)),
            columnMap: ['name' => '客户姓名', 'id_card' => '证件号码', 'gender' => '性别'],
        );

        self::assertSame(1, $import->getFailedRowsCount());

        $bytes = $this->captureResponseContent(FixtureImporter::getFailedRowsDownloader()($import));

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-failed-assert-');
        file_put_contents($path, $bytes);
        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertSame(['客户姓名', '证件号码', '性别', '失败原因'], $sheet->toArray()[0]);
        self::assertSame('12345', $sheet->getCell('B2')->getValue());
        self::assertSame('李四', $sheet->getCell('A2')->getValue());

        self::assertSame('none', $sheet->getDataValidation('A2')->getType());
        self::assertSame('none', $sheet->getDataValidation('B2')->getType());
        self::assertSame('list', $sheet->getDataValidation('C2')->getType());

        unlink($path);
    }

    public function test_constraint_assembly_exception_degrades_to_plain_data_sheet(): void
    {
        $importerClass = ThrowingSourceImporter::class;

        $builder = $this->fixtureBuilder();
        $builder->setText('A3', '李四')->setText('B3', '12345');

        $import = $this->importRows(
            $this->makeFakeUpload('data.xlsx', $this->bytes($builder)),
            importerClass: $importerClass,
        );

        self::assertSame(1, $import->getFailedRowsCount());

        $bytes = $this->captureResponseContent($importerClass::getFailedRowsDownloader()($import));

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-failed-assert-');
        file_put_contents($path, $bytes);

        $spreadsheet = IOFactory::load($path);

        self::assertSame(['导入数据'], $spreadsheet->getSheetNames());
        self::assertCount(0, $spreadsheet->getSheet(0)->getDataValidationCollection());

        $rows = $this->readXlsxRows($bytes);

        self::assertSame(['姓名', '身份证号', '性别', '失败原因'], $rows[0]);
        self::assertSame('李四', $rows[1][0]);
        self::assertSame('12345', $rows[1][1]);

        unlink($path);
    }

    public function test_500_row_sync_import_benchmark(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '姓名')
            ->setText('B1', '身份证号')
            ->setText('C1', '性别');

        foreach (range(2, 501) as $rowIndex) {
            $idCard = '441302199001'.str_pad((string) ($rowIndex + 10000), 6, '0', STR_PAD_LEFT);
            $builder->setText("A{$rowIndex}", "成员{$rowIndex}");
            $builder->setText("B{$rowIndex}", $idCard);
            $builder->setText("C{$rowIndex}", $rowIndex % 2 === 0 ? '男' : '女');
        }

        $file = $this->makeFakeUpload('bulk.xlsx', $this->bytes($builder));

        $startedAt = microtime(true);

        $import = $this->importRows($file);

        $elapsedSeconds = microtime(true) - $startedAt;
        $peakMemoryMb = memory_get_peak_usage(true) / 1024 / 1024;

        self::assertSame(500, $import->refresh()->successful_rows);
        self::assertSame(0, $import->getFailedRowsCount());
        self::assertSame(500, FixtureMember::count());

        fwrite(STDERR, sprintf(
            "\n[benchmark] 500 行 sync 导入：耗时 %.2fs，进程峰值内存 %.1f MB\n",
            $elapsedSeconds,
            $peakMemoryMb,
        ));

        // sync 请求时长的宽松上界（业务超限可走官方异步开关，包不新增机制）
        self::assertLessThan(60, $elapsedSeconds);
    }

    /**
     * 复刻官方 ImportAction action 闭包的执行路径。
     *
     * @param  array<string, string>|null  $columnMap
     */
    private function importRows(
        TemporaryUploadedFile $file,
        string $fileName = 'data.xlsx',
        ?string $importerClass = null,
        ?array $columnMap = null,
    ): Import {
        $importerClass ??= FixtureImporter::class;

        $action = XlsxImportAction::make()->importer($importerClass);

        $csvStream = $action->getUploadedFileStream($file);

        $csvReader = CsvReader::from($csvStream);
        $csvReader->setHeaderOffset(0);
        $csvResults = (new Statement)->process($csvReader);

        $import = new Import;
        $import->user()->associate($this->user);
        $import->file_name = $fileName;
        $import->file_path = $file->getRealPath();
        $import->importer = $importerClass;
        $import->total_rows = $csvResults->count();
        $import->save();

        $chunks = (new ChunkIterator($csvResults->getRecords(), chunkSize: 100))->get();

        foreach ($chunks as $chunk) {
            app(ImportCsv::class, [
                'import' => $import,
                'rows' => base64_encode(serialize($chunk)),
                'columnMap' => $columnMap ?? ['name' => '姓名', 'id_card' => '身份证号', 'gender' => '性别'],
                'options' => [],
            ])->handle();
        }

        return $import->refresh();
    }

    private function fixtureBuilder(): XlsxBuilder
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '姓名')
            ->setText('B1', '身份证号')
            ->setText('C1', '性别')
            ->setText('A2', '张三')
            ->setText('B2', '441302199001011234')
            ->setText('C2', '男');

        return $builder;
    }

    private function bytes(XlsxBuilder $builder): string
    {
        return (string) file_get_contents($builder->toTempFile());
    }

    private function makeFakeUpload(string $originalName, string $contents): TemporaryUploadedFile
    {
        $disk = Storage::fake('local');
        $disk->put('livewire-tmp/'.$originalName, $contents);

        return new TemporaryUploadedFile($originalName, 'local');
    }

    private function captureResponseContent(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function readXlsxRows(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-download-');
        file_put_contents($path, $bytes);

        $reader = new Xlsx;
        $sheet = $reader->load($path)->getSheet(0);

        $rows = [];

        foreach ($sheet->toArray() as $row) {
            if (array_filter($row, fn ($cell): bool => $cell !== null && $cell !== '') === []) {
                continue;
            }

            $rows[] = $row;
        }

        unlink($path);

        return $rows;
    }

    /**
     * 直查落盘 XML 的 list 校验抑制态清单（showDropDown="1" 在 OOXML 语义中
     * 为抑制下拉箭头；读回断言会被读回默认值掩盖）。
     *
     * @return array<int, string>
     */
    private function dropdownSuppressions(string $xlsxBytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-xml-');
        file_put_contents($path, $xlsxBytes);

        $zip = new ZipArchive;
        $zip->open($path);

        $violations = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! str_starts_with($name, 'xl/worksheets/sheet')) {
                continue;
            }

            foreach (explode('<dataValidation ', (string) $zip->getFromIndex($index)) as $chunk) {
                if (! str_starts_with($chunk, 'type="list"')) {
                    continue;
                }

                $attributes = (string) str($chunk)->before('>');

                if (str_contains($attributes, 'showDropDown="1"')) {
                    $violations[] = $name.': '.$attributes;
                }
            }
        }

        $zip->close();
        unlink($path);

        return $violations;
    }

    /**
     * 断言数据单元格以字符串类型落盘（t="s"/"inlineStr"/"str"）：
     * 数值绑定（无 t 或 t="n"）会让长数字以科学计数法呈现，且重传触发检测死循环。
     */
    private function assertCellPersistedAsString(string $xlsxBytes, string $coordinate, string $message): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-xml-');
        file_put_contents($path, $xlsxBytes);

        $zip = new ZipArchive;
        $zip->open($path);

        $matched = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if ($name !== 'xl/worksheets/sheet1.xml') {
                continue;
            }

            preg_match(
                sprintf('/<c r="%s"([^>]*)>/', preg_quote($coordinate, '/')),
                (string) $zip->getFromIndex($index),
                $matched,
            );
        }

        $zip->close();
        unlink($path);

        self::assertNotEmpty($matched, "落盘 XML 中未找到单元格 {$coordinate}");
        self::assertMatchesRegularExpression(
            '/t="(s|inlineStr|str)"/',
            (string) $matched[1],
            "{$coordinate} 须以字符串类型落盘：{$message}",
        );
    }
}
