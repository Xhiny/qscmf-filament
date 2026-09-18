<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Filament\Actions\Imports\Downloaders\Contracts\Downloader;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Quansitech\Cmf\Import\Template\PhpSpreadsheetDriver;
use Quansitech\Cmf\Import\Template\XlsxImportColumn;
use Stringable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 失败清单 xlsx 下载器：失败行原数据（表头保持原文件表头序）+ 末列中文「失败原因」，
 * 并按「表头 ≈ 导入列 label」匹配挂载与模板一致的单元格约束。
 *
 * - 数据单元格一律 setValueExplicit 字符串写入：数值绑定会让长数字（身份证等）
 *   打开即科学计数法，且重传触发检测规则形成失败循环
 * - 匹配口径镜像官方 ImportColumn::getGuesses()；匹配不上的列降级为无约束、原数据保留
 * - 装配含 DropdownSource 真查询，整体 try/catch 异常降级为无约束纯数据清单
 */
class XlsxFailedRowsDownloader implements Downloader
{
    public const REASON_COLUMN = '失败原因';

    public function __invoke(Import $import): StreamedResponse
    {
        /** @var Collection<int, FailedImportRow> $failedRows */
        $failedRows = $import->failedRows()->orderBy('id')->get();

        $headers = $this->headers($failedRows);

        try {
            $matchedColumns = $this->matchedColumns($headers, $import->importer);

            $spreadsheet = $this->buildSpreadsheet($import->importer, $failedRows, $headers, $matchedColumns);
        } catch (Throwable $assemblyFailure) {
            report($assemblyFailure);

            $spreadsheet = $this->buildSpreadsheet($import->importer, $failedRows, $headers, null);
        }

        $tempFilePath = (string) tempnam(sys_get_temp_dir(), 'xlsx-failed-rows-');

        (new XlsxWriter($spreadsheet))->save($tempFilePath);
        $spreadsheet->disconnectWorksheets();

        return response()->streamDownload(static function () use ($tempFilePath): void {
            readfile($tempFilePath);
            @unlink($tempFilePath);
        }, $this->fileName($import), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * 表头 = 首条失败行原文件表头序 + 末列「失败原因」（与各失败行 data 键序一致，
     * 官方按 columnMap 反查原表头落库）。快照表头已含「失败原因」时（重传链路）
     * 先剔除再追加，保障清单恒为一列「失败原因」。
     *
     * @param  Collection<int, FailedImportRow>  $failedRows
     * @return array<int, string>
     */
    private function headers(Collection $failedRows): array
    {
        $headers = $failedRows->isNotEmpty()
            ? array_map(strval(...), array_keys($failedRows->first()->data ?? []))
            : [];

        $headers = array_values(array_filter(
            $headers,
            fn (string $header): bool => $header !== self::REASON_COLUMN,
        ));

        $headers[] = self::REASON_COLUMN;

        return $headers;
    }

    /**
     * 表头 → 声明了模板校验的导入列：镜像 getGuesses() 归一口径匹配，
     * 先注册者胜；末列「失败原因」恒为 null（不挂约束）；匹配不上为 null 占位。
     *
     * @param  array<int, string>  $headers
     * @return array<int, XlsxImportColumn|null>
     */
    private function matchedColumns(array $headers, string $importerClass): array
    {
        $guessMap = [];

        foreach ($importerClass::getColumns() as $column) {
            if (! $column instanceof XlsxImportColumn) {
                continue;
            }

            foreach ($column->getGuesses() as $guess) {
                $guessMap[$guess] ??= $column;
            }
        }

        $matched = [];

        foreach (array_slice($headers, 0, -1) as $header) {
            $matched[] = $guessMap[$this->normalizeHeader($header)] ?? null;
        }

        $matched[] = null;

        return $matched;
    }

    /**
     * header 归一变体：与官方 getGuesses() 的 array_reduce 变换同口径
     * （lower / `-_`→空格 / 空格→`-_`），命中任一变体即视为同一表头。
     */
    private function normalizeHeader(string $header): string
    {
        $lower = mb_strtolower($header);

        $spaced = str_replace(['-', '_'], ' ', $lower);

        if (str_contains($spaced, ' ')) {
            return str_replace(' ', '-', $spaced);
        }

        return $spaced;
    }

    /**
     * @param  Collection<int, FailedImportRow>  $failedRows
     * @param  array<int, string>  $headers  含末列「失败原因」
     * @param  array<int, XlsxImportColumn|null>|null  $matchedColumns  null = 纯数据降级构建
     */
    private function buildSpreadsheet(
        string $importerClass,
        Collection $failedRows,
        array $headers,
        ?array $matchedColumns,
    ): Spreadsheet {
        $driver = new PhpSpreadsheetDriver;

        $spreadsheet = new Spreadsheet;
        $mainSheet = $spreadsheet->getActiveSheet();
        $mainSheet->setTitle(PhpSpreadsheetDriver::MAIN_SHEET_NAME);
        $mainSheet->freezePane('A2');

        $this->writeRow($mainSheet, 1, $headers);

        foreach ($failedRows as $rowIndex => $failedImportRow) {
            $data = $failedImportRow->data ?? [];
            unset($data[self::REASON_COLUMN]);

            $this->writeRow($mainSheet, $rowIndex + 2, [
                ...array_map(strval(...), array_values($data)),
                $failedImportRow->validation_error ?? '系统错误',
            ]);
        }

        if ($failedRows->isNotEmpty()) {
            $this->formatReasonColumn($mainSheet, count($headers), $failedRows->count() + 1);
        }

        if ($matchedColumns !== null) {
            $optionsSheet = new Worksheet($spreadsheet, PhpSpreadsheetDriver::OPTIONS_SHEET_NAME);
            $spreadsheet->addSheet($optionsSheet);

            $hasCustomGuideLines = method_exists($importerClass, 'getTemplateGuideLines');
            $guideLines = $hasCustomGuideLines
                ? array_values((array) $importerClass::getTemplateGuideLines())
                : $driver->baseGuideLines();

            $guideLines = $driver->applyColumnConstraints(
                $mainSheet,
                $optionsSheet,
                $matchedColumns,
                firstDataRow: 2,
                lastDataRow: max($failedRows->count() + 1, 2),
                guideLines: $guideLines,
                appendDropdownGuideLines: ! $hasCustomGuideLines,
            );

            $optionsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

            $driver->writeGuideSheet($spreadsheet, $guideLines);
        }

        return $spreadsheet;
    }

    /**
     * @param  array<int, Stringable|string|int|float|null>  $values
     */
    private function writeRow(Worksheet $sheet, int $rowNumber, array $values): void
    {
        foreach (array_values($values) as $columnIndex => $value) {
            $sheet
                ->getCell(Coordinate::stringFromColumnIndex($columnIndex + 1).(string) $rowNumber, true)
                ->setValueExplicit((string) $value, DataType::TYPE_STRING);
        }
    }

    private function formatReasonColumn(Worksheet $sheet, int $headerCount, int $lastRow): void
    {
        $columnLetter = Coordinate::stringFromColumnIndex($headerCount);

        $sheet->getColumnDimension($columnLetter)->setWidth(50);
        $sheet
            ->getStyle($columnLetter.'2:'.$columnLetter.$lastRow)
            ->getAlignment()
            ->setWrapText(true);
    }

    private function fileName(Import $import): string
    {
        return '导入失败清单-'.$import->getKey().'-'.
            (string) str($import->file_name)->beforeLast('.')->remove('.').'.xlsx';
    }
}
