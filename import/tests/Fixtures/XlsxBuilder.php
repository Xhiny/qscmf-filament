<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * 测试用 xlsx 构造器：用 PhpSpreadsheet 产出真实 xlsx 字节流，
 * 覆盖数值 / 文本 / 日期 / 公式 / 合并单元格 / 多 sheet 等场景。
 */
final class XlsxBuilder
{
    private Spreadsheet $spreadsheet;

    private bool $preCalculateFormulas = true;

    public function __construct()
    {
        $this->spreadsheet = new Spreadsheet;
    }

    public static function make(): self
    {
        return new self;
    }

    public function sheet(): Worksheet
    {
        return $this->spreadsheet->getActiveSheet();
    }

    public function addSheet(string $name): Worksheet
    {
        $sheet = new Worksheet($this->spreadsheet, $name);
        $this->spreadsheet->addSheet($sheet);

        return $sheet;
    }

    public function calendar1904(): self
    {
        $this->spreadsheet->setExcelCalendar(Date::CALENDAR_MAC_1904);

        return $this;
    }

    /**
     * 关闭保存时公式预计算（模拟未经 Excel 保存、无缓存值的程序生成文件）。
     */
    public function withoutFormulaPrecalculation(): self
    {
        $this->preCalculateFormulas = false;

        return $this;
    }

    /**
     * 以文本（字符串）写入单元格，防止 PhpSpreadsheet 自动转数字。
     */
    public function setText(string $coordinate, string $value, ?Worksheet $sheet = null): self
    {
        ($sheet ?? $this->sheet())->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

        return $this;
    }

    public function setNumeric(string $coordinate, int|float $value, ?Worksheet $sheet = null): self
    {
        ($sheet ?? $this->sheet())->setCellValue($coordinate, $value);

        return $this;
    }

    public function setDate(string $coordinate, DateTimeImmutable $value, ?Worksheet $sheet = null): self
    {
        $sheet ??= $this->sheet();

        // Date::PHPToExcel 固定用 1900 日历；1904 工作簿需先换算序列（差 1462 天）
        $serial = Date::PHPToExcel($value);

        if ($this->spreadsheet->getExcelCalendar() === Date::CALENDAR_MAC_1904) {
            $serial -= 1462;
        }

        $sheet->setCellValue($coordinate, $serial);
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');

        return $this;
    }

    public function setFormula(string $coordinate, string $formula, int|float $cachedValue, ?Worksheet $sheet = null): self
    {
        $sheet ??= $this->sheet();
        $sheet->setCellValue($coordinate, $formula);
        $sheet->getCell($coordinate)->setCalculatedValue($cachedValue);

        return $this;
    }

    /**
     * 无缓存值公式（真实场景：程序生成、未经 Excel 计算保存）。
     */
    public function setFormulaWithoutCache(string $coordinate, string $formula, ?Worksheet $sheet = null): self
    {
        ($sheet ?? $this->sheet())->setCellValue($coordinate, $formula);

        return $this;
    }

    public function merge(string $range, ?Worksheet $sheet = null): self
    {
        ($sheet ?? $this->sheet())->mergeCells($range);

        return $this;
    }

    public function setTimeValue(string $coordinate, DateInterval $interval, ?Worksheet $sheet = null): self
    {
        $sheet ??= $this->sheet();
        $sheet->setCellValue($coordinate, Date::PHPToExcel($interval));
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('[h]:mm:ss');

        return $this;
    }

    public function toTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-fixture-');

        $writer = new XlsxWriter($this->spreadsheet);
        $writer->setPreCalculateFormulas($this->preCalculateFormulas);
        $writer->save($path);
        $this->spreadsheet->disconnectWorksheets();

        return $path;
    }
}
