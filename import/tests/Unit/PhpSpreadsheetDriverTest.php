<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use Filament\Actions\Imports\ImportColumn;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Quansitech\Cmf\Import\Template\PhpSpreadsheetDriver;
use Quansitech\Cmf\Import\Tests\Fixtures\FixtureImporter;
use Quansitech\Cmf\Import\Tests\TestCase;
use ZipArchive;

class PhpSpreadsheetDriverTest extends TestCase
{
    public function test_generated_template_sheet_structure(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $spreadsheet = IOFactory::load($path);

        self::assertSame(['导入数据', '_options', '填写说明'], $spreadsheet->getSheetNames());

        $optionsSheet = $spreadsheet->getSheetByName('_options');

        self::assertNotNull($optionsSheet);
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $optionsSheet->getSheetState());

        unlink($path);
    }

    public function test_headers_use_chinese_labels_with_example_row(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertSame('姓名', $sheet->getCell('A1')->getValue());
        self::assertSame('身份证号', $sheet->getCell('B1')->getValue());
        self::assertSame('性别', $sheet->getCell('C1')->getValue());

        self::assertSame('张三', $sheet->getCell('A2')->getValue());
        self::assertSame('441302199001011234', $sheet->getCell('B2')->getFormattedValue());
        self::assertSame('男', $sheet->getCell('C2')->getValue());

        unlink($path);
    }

    public function test_text_lock_column_num_fmt_is_text(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertSame('@', $sheet->getStyle('A2:A1000')->getNumberFormat()->getFormatCode());
        self::assertSame('@', $sheet->getStyle('B2:B1000')->getNumberFormat()->getFormatCode());
        self::assertNotSame('@', $sheet->getStyle('C2:C1000')->getNumberFormat()->getFormatCode());

        unlink($path);
    }

    public function test_length_column_gets_text_length_validation(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $sheet = IOFactory::load($path)->getSheet(0);

        $validation = $sheet->getDataValidation('B2');

        self::assertSame('textLength', $validation->getType());
        self::assertSame('between', $validation->getOperator());
        self::assertSame('18', $validation->getFormula1());
        self::assertSame('18', $validation->getFormula2());

        unlink($path);
    }

    public function test_dropdown_column_writes_hidden_options_sheet_with_range_reference(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $optionsSheet = $spreadsheet->getSheetByName('_options');

        self::assertSame('男', $optionsSheet->getCell('A1')->getValue());
        self::assertSame('女', $optionsSheet->getCell('A2')->getValue());

        $validation = $sheet->getDataValidation('C2');

        self::assertSame('list', $validation->getType());
        self::assertSame('_options!$A$1:$A$2', $validation->getFormula1());
        // PhpSpreadsheet 层语义：true = 显示下拉；落盘真实属性由 XML 用例直查兜底
        self::assertTrue($validation->getShowDropDown());

        unlink($path);
    }

    public function test_guide_sheet_generated_without_timestamp_and_length_notes(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $guideSheet = IOFactory::load($path)->getSheetByName('填写说明');

        $lines = [];

        foreach ($guideSheet->rangeToArray('A1:A30', null, true, true, false) as $row) {
            foreach ($row as $cell) {
                if (filled($cell)) {
                    $lines[] = (string) $cell;
                }
            }
        }

        $contents = implode("\n", $lines);

        self::assertStringContainsString('下拉列', $contents);
        self::assertStringContainsString('性别固定选项', $contents);
        self::assertStringNotContainsString('快照时间', $contents, '运营无需生成时间戳');
        self::assertStringNotContainsString('长度限制', $contents, '长度为技术细节，不进入运营说明');
        self::assertStringNotContainsString('模板生成时间', $contents);

        unlink($path);
    }

    public function test_custom_guide_lines_take_over_guide_sheet_entirely(): void
    {
        $importerClass = new class extends FixtureImporter
        {
            public function __construct() {}

            public static function getTemplateGuideLines(): array
            {
                return ['运营视角说明第一行', '运营视角说明第二行'];
            }
        };

        $path = (new PhpSpreadsheetDriver)->generate($importerClass::class);

        $guideSheet = IOFactory::load($path)->getSheetByName('填写说明');

        $lines = [];

        foreach ($guideSheet->rangeToArray('A1:A30', null, true, true, false) as $row) {
            foreach ($row as $cell) {
                if (filled($cell)) {
                    $lines[] = (string) $cell;
                }
            }
        }

        self::assertSame(['运营视角说明第一行', '运营视角说明第二行'], $lines);

        unlink($path);
    }

    public function test_persisted_dropdown_xml_must_not_suppress_arrow(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        // 读回断言测不到落盘属性，须直查 xl/worksheets 原文（showDropDown="1" = 抑制箭头）
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

        self::assertSame([], $violations, 'list 校验落盘不得携带 showDropDown="1"（会抑制 Excel/WPS 下拉箭头）');
    }

    public function test_plain_import_column_gets_no_template_validation(): void
    {
        $importerClass = new class extends FixtureImporter
        {
            public function __construct() {}

            public static function getColumns(): array
            {
                return [
                    ImportColumn::make('name')->label('姓名')->example('张三'),
                ];
            }
        };

        $path = (new PhpSpreadsheetDriver)->generate($importerClass::class);

        $spreadsheet = IOFactory::load($path);

        self::assertSame('姓名', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
        self::assertCount(0, $spreadsheet->getSheet(0)->getDataValidationCollection());

        unlink($path);
    }
}
