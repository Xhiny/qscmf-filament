<?php

namespace Quansitech\Cmf\Import\Template;

use Filament\Actions\Imports\ImportColumn;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Quansitech\Cmf\Import\Template\Contracts\TemplateGenerator;

/**
 * PhpSpreadsheet 模板驱动：openspout 4.x 无 DataValidation API，模板生成侧
 * 选用 PhpSpreadsheet（读取与失败导出仍走 openspout 流式）。
 */
class PhpSpreadsheetDriver implements TemplateGenerator
{
    /**
     * 模板校验覆盖的数据行数（表头之下）。
     */
    protected const MAX_TEMPLATE_ROWS = 1000;

    public const MAIN_SHEET_NAME = '导入数据';

    public const OPTIONS_SHEET_NAME = '_options';

    public const GUIDE_SHEET_NAME = '填写说明';

    public function generate(string $importerClass): string
    {
        $columns = $importerClass::getColumns();

        $spreadsheet = new Spreadsheet;
        $mainSheet = $spreadsheet->getActiveSheet();
        $mainSheet->setTitle(self::MAIN_SHEET_NAME);
        $mainSheet->freezePane('A2');

        $this->writeHeaderAndExampleRow($mainSheet, $columns);

        $optionsSheet = new Worksheet($spreadsheet, self::OPTIONS_SHEET_NAME);
        $spreadsheet->addSheet($optionsSheet);

        $hasCustomGuideLines = method_exists($importerClass, 'getTemplateGuideLines');
        $guideLines = $hasCustomGuideLines
            ? array_values((array) $importerClass::getTemplateGuideLines())
            : $this->baseGuideLines();

        $guideLines = $this->applyColumnConstraints(
            $mainSheet,
            $optionsSheet,
            array_values($columns),
            firstDataRow: 2,
            lastDataRow: static::MAX_TEMPLATE_ROWS,
            guideLines: $guideLines,
            appendDropdownGuideLines: ! $hasCustomGuideLines,
        );

        $optionsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $this->writeGuideSheet($spreadsheet, $guideLines);

        $tempFilePath = (string) tempnam(sys_get_temp_dir(), 'xlsx-template-');

        (new XlsxWriter($spreadsheet))->save($tempFilePath);
        $spreadsheet->disconnectWorksheets();

        return $tempFilePath;
    }

    /**
     * 列约束装配：按列声明在指定行区间挂载 numFmt `@` / textLength / 下拉
     * （快照写入 _options sheet + 范围引用），只关心列位与行区间、与写入什么
     * 数据行解耦——模板生成与失败清单导出共用同一装配口径。
     *
     * @param  array<int, ImportColumn|null>  $columns  顺序列集（索引 = 列序，0 起始）；
     *                                                  普通 ImportColumn 或未匹配列传 null 占位，该列无模板约束
     * @param  array<int, string>  $guideLines
     * @return array<int, string> 追加下拉辅助说明后的说明行集合
     */
    public function applyColumnConstraints(
        Worksheet $mainSheet,
        Worksheet $optionsSheet,
        array $columns,
        int $firstDataRow,
        int $lastDataRow,
        array $guideLines = [],
        bool $appendDropdownGuideLines = true,
    ): array {
        $dropdownColumnIndex = 0;

        foreach (array_values($columns) as $index => $column) {
            if (! $column instanceof XlsxImportColumn) {
                continue;
            }

            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $dataRange = sprintf('%s%d:%s%d', $columnLetter, $firstDataRow, $columnLetter, $lastDataRow);

            if ($column->isTextLocked()) {
                $mainSheet->getStyle($dataRange)->getNumberFormat()->setFormatCode('@');
            }

            if (($validation = $this->textLengthValidation($column)) !== null) {
                $mainSheet->setDataValidation($dataRange, $validation);
            }

            if (($source = $column->getDropdownSource()) !== null) {
                $dropdownColumnIndex++;
                $optionsColumnLetter = Coordinate::stringFromColumnIndex($dropdownColumnIndex);
                $options = $source->options();

                foreach (array_values($options) as $rowIndex => $option) {
                    $optionsSheet->setCellValue($optionsColumnLetter.($rowIndex + 1), $option);
                }

                $lastOptionRow = max(count($options), 1);
                $mainSheet->setDataValidation($dataRange, $this->dropdownValidation(
                    sprintf('%s!$%s$1:$%s$%d', $optionsSheet->getTitle(), $optionsColumnLetter, $optionsColumnLetter, $lastOptionRow),
                ));

                if ($appendDropdownGuideLines) {
                    $guideLines[] = sprintf(
                        '「%s」为下拉列（辅助说明）：从下拉列表选择；选项为文件生成时的数据源快照——%s。',
                        $this->columnTitle($column),
                        $source->description(),
                    );
                }
            }
        }

        return $guideLines;
    }

    /**
     * @param  array<int, string>  $guideLines
     */
    public function writeGuideSheet(Spreadsheet $spreadsheet, array $guideLines): Worksheet
    {
        $guideSheet = new Worksheet($spreadsheet, self::GUIDE_SHEET_NAME);
        $spreadsheet->addSheet($guideSheet);

        foreach (array_values($guideLines) as $rowIndex => $line) {
            $guideSheet->setCellValue('A'.($rowIndex + 1), $line);
        }
        $guideSheet->getColumnDimension('A')->setAutoSize(true);

        return $guideSheet;
    }

    /**
     * @param  array<int, ImportColumn>  $columns
     */
    private function writeHeaderAndExampleRow(Worksheet $sheet, array $columns): void
    {
        foreach (array_values($columns) as $index => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);

            $sheet->setCellValue($columnLetter.'1', $this->columnTitle($column));
            $sheet->setCellValue($columnLetter.'2', (string) (array_values($column->getExamples())[0] ?? ''));
        }
    }

    private function columnTitle(ImportColumn $column): string
    {
        return $column->getLabel() ?? $column->getExampleHeader();
    }

    private function textLengthValidation(XlsxImportColumn $column): ?DataValidation
    {
        $min = $column->getMinLength();
        $max = $column->getMaxLength();

        if ($min === null && $max === null) {
            return null;
        }

        $validation = $this->baseValidation();
        $validation->setType('textLength');

        if ($min !== null && $max !== null) {
            $validation->setOperator('between');
            $validation->setFormula1((string) $min);
            $validation->setFormula2((string) $max);
            $validation->setError(sprintf('长度须在 %d ~ %d 个字符之间', $min, $max));
        } elseif ($min !== null) {
            $validation->setOperator('greaterThanOrEqual');
            $validation->setFormula1((string) $min);
            $validation->setError(sprintf('长度不得少于 %d 个字符', $min));
        } else {
            $validation->setOperator('lessThanOrEqual');
            $validation->setFormula1((string) $max);
            $validation->setError(sprintf('长度不得超过 %d 个字符', $max));
        }

        $validation->setErrorTitle('长度不符');

        return $validation;
    }

    private function dropdownValidation(string $formulaRange): DataValidation
    {
        $validation = $this->baseValidation();
        $validation->setType('list');
        $validation->setFormula1($formulaRange);
        $validation->setErrorTitle('选项无效');
        $validation->setError('请从下拉列表中选择有效选项');

        // PhpSpreadsheet 的 showDropDown 属性语义与 OOXML 相反（PHP true = 显示下拉），
        // Writer 落盘时取反：PHP true → showDropDown="0"（箭头显示）。
        // 默认 false 会落盘 showDropDown="1"（OOXML 语义 = 抑制箭头），Excel/WPS 中下拉不可见
        $validation->setShowDropDown(true);

        return $validation;
    }

    private function baseValidation(): DataValidation
    {
        $validation = new DataValidation;
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);

        return $validation;
    }

    /**
     * 默认技术性说明行（接入方未定义 getTemplateGuideLines() 时使用），
     * 模板生成与失败清单导出共用。
     *
     * @return array<int, string>
     */
    public function baseGuideLines(): array
    {
        return [
            '填写说明',
            '请保留表头行，勿修改列名与列顺序；上传时支持按列名自动映射。',
            '请直接在「导入数据」工作表中填写，勿手工新建表格输入长数字，避免精度截断。',
        ];
    }
}
