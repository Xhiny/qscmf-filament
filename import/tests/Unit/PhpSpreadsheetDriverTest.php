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
    public function test_生成模板的工作表结构(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $spreadsheet = IOFactory::load($path);

        self::assertSame(['导入数据', '_options', '填写说明'], $spreadsheet->getSheetNames());

        $optionsSheet = $spreadsheet->getSheetByName('_options');

        self::assertNotNull($optionsSheet);
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $optionsSheet->getSheetState());

        unlink($path);
    }

    public function test_表头为中文_label_且含示例行(): void
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

    public function test_text_lock_列的_num_fmt_为文本(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertSame('@', $sheet->getStyle('A2:A1000')->getNumberFormat()->getFormatCode());
        self::assertSame('@', $sheet->getStyle('B2:B1000')->getNumberFormat()->getFormatCode());
        self::assertNotSame('@', $sheet->getStyle('C2:C1000')->getNumberFormat()->getFormatCode());

        unlink($path);
    }

    public function test_length_列的_text_length_校验(): void
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

    public function test_dropdown_列写入隐藏_options_sheet_并以范围引用(): void
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
        // PhpSpreadsheet 层语义：showDropDown=true = 显示下拉（Writer 落盘取反为 "0"）；
        // 落盘真实属性由 test_下拉校验落盘_xml_不得抑制下拉箭头 以 XML 原文兜底
        self::assertTrue($validation->getShowDropDown());

        unlink($path);
    }

    public function test_填写说明_sheet_默认生成且不含时间戳与长度说明(): void
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

    public function test_接入方定义_get_template_guide_lines_时完全接管说明_sheet(): void
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

    public function test_下拉校验落盘_xml_不得抑制下拉箭头(): void
    {
        $path = (new PhpSpreadsheetDriver)->generate(FixtureImporter::class);

        // 读回断言测不到落盘属性（读回默认值掩盖真实 XML），须直查 xl/worksheets 原文；
        // OOXML showDropDown="1" 语义为「抑制下拉箭头」，真机表现为下拉不可见
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

    public function test_普通_import_column_列无模板校验且不报错(): void
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
