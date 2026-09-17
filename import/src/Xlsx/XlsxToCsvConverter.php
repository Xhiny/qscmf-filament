<?php

namespace Quansitech\Cmf\Import\Xlsx;

use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;

/**
 * xlsx → UTF-8 CSV 流转换器（内存中间格式，仅第一个工作表）。
 *
 * 官方 ImportAction 的 4 个文件消费点全部经 getUploadedFileStream() 读取 CSV，
 * 本转换器使它们无感知地接受 xlsx 输入。可疑值（科学计数法 / 截断长整数）由
 * CellNormalizer 原样透传，不在此层拒绝。
 */
final class XlsxToCsvConverter
{
    /**
     * 单个合并范围展开后的格子数上限，防御异常膨胀的文件。
     */
    private const MAX_MERGE_RANGE_CELLS = 10000;

    /**
     * @param  string  $localFilePath  本地可读的 xlsx 文件路径
     * @return resource 已 rewind 的 php://temp UTF-8 CSV 流
     *
     * @throws InvalidXlsxFileException 文件不是合法 xlsx / 没有可读取的数据
     */
    public function convert(string $localFilePath)
    {
        [$sheet, $reader] = $this->openFirstSheet($localFilePath);

        $mergeMap = []; // [row][col] => [anchorRow, anchorCol]
        $anchorCells = []; // [row][col] => true，锚点格子需要缓存值供跨行合并填充
        foreach ($sheet->getMergeCells() as $range) {
            $this->expandMergeRange($range, $mergeMap, $anchorCells);
        }

        $csvStream = fopen('php://temp', 'r+');
        if ($csvStream === false) {
            throw new InvalidXlsxFileException('无法创建内部转换流');
        }

        $anchorValues = []; // [row][col] => string
        $headerColumnCount = null;
        $rowIndex = -1;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCells();
            $rowIndex++;

            $values = [];
            $isBlankRow = true;

            foreach ($cells as $columnIndex => $cell) {
                $value = CellNormalizer::normalize($cell);
                $values[$columnIndex] = $value;

                if ($value !== null && $value !== '') {
                    $isBlankRow = false;
                }
            }

            // 合并单元格取左上值：非锚点格子填充锚点值
            foreach ($mergeMap[$rowIndex] ?? [] as $columnIndex => $anchor) {
                [$anchorRow, $anchorColumn] = $anchor;

                $values[$columnIndex] = $anchorRow === $rowIndex
                    ? ($values[$anchorColumn] ?? null)
                    : ($anchorValues[$anchorRow][$anchorColumn] ?? null);

                if ($values[$columnIndex] !== null && $values[$columnIndex] !== '') {
                    $isBlankRow = false;
                }
            }

            foreach ($anchorCells[$rowIndex] ?? [] as $columnIndex => $true) {
                $anchorValues[$rowIndex][$columnIndex] = $values[$columnIndex] ?? null;
            }
            unset($anchorValues[$rowIndex - 1]);

            if ($isBlankRow) {
                continue;
            }

            if ($headerColumnCount === null) {
                $headerColumnCount = count($values);
                $values = array_map(
                    static fn (?string $value): string => (string) $value,
                    $values,
                );
                // 表头去除首尾空白，保证与列 label 的自动映射匹配
                $values = array_map(trim(...), $values);
            } else {
                $values = $this->padRowToHeader($values, $headerColumnCount);
            }

            fputcsv($csvStream, $values);
        }

        $reader->close();

        if ($headerColumnCount === null) {
            throw new InvalidXlsxFileException('文件不包含任何数据行，请下载导入模板填写后再上传');
        }

        rewind($csvStream);

        return $csvStream;
    }

    /**
     * @return array{0: Sheet, 1: Reader}
     */
    private function openFirstSheet(string $localFilePath): array
    {
        $options = new Options;
        $options->SHOULD_FORMAT_DATES = true;
        // 保留空行使行号与工作表坐标对齐（合并单元格映射依赖真实行号），
        // 空行跳过由本转换器自行处理
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_LOAD_MERGE_CELLS = true;

        $reader = new Reader($options);

        try {
            $reader->open($localFilePath);
        } catch (OpenSpoutException|\RuntimeException $exception) {
            throw new InvalidXlsxFileException(
                '文件不是有效的 xlsx 文件，请下载导入模板填写后再上传（'.$exception->getMessage().'）',
                0,
                $exception,
            );
        }

        $sheets = iterator_to_array($reader->getSheetIterator(), false);

        $sheet = $sheets[0] ?? null;

        if ($sheet === null) {
            $reader->close();

            throw new InvalidXlsxFileException('文件中没有可读取的工作表，请下载导入模板填写后再上传');
        }

        return [$sheet, $reader];
    }

    /**
     * @param  array<int|null>  $values
     * @return array<int, string>
     */
    private function padRowToHeader(array $values, int $headerColumnCount): array
    {
        $padded = [];

        for ($columnIndex = 0; $columnIndex < $headerColumnCount; $columnIndex++) {
            $padded[] = $values[$columnIndex] ?? '';
        }

        return $padded;
    }

    /**
     * @param  array<int, array<int, array{0: int, 1: int}>>  $mergeMap
     * @param  array<int, array<int, true>>  $anchorCells
     */
    private function expandMergeRange(string $range, array &$mergeMap, array &$anchorCells): void
    {
        [$from, $to] = array_pad(explode(':', $range), 2, null);

        if ($to === null) {
            return;
        }

        [$startColumn, $startRow] = $this->parseCoordinate($from);
        [$endColumn, $endRow] = $this->parseCoordinate($to);

        $cellCount = ($endRow - $startRow + 1) * ($endColumn - $startColumn + 1);

        if ($cellCount > self::MAX_MERGE_RANGE_CELLS) {
            return;
        }

        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($column = $startColumn; $column <= $endColumn; $column++) {
                if ($row === $startRow && $column === $startColumn) {
                    $anchorCells[$row][$column] = true;

                    continue;
                }

                $mergeMap[$row][$column] = [$startRow, $startColumn];
            }
        }
    }

    /**
     * "B3" => [1, 3]（列、行均从 0 起）
     *
     * @return array{0: int, 1: int}
     */
    private function parseCoordinate(string $coordinate): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', strtoupper(trim($coordinate)), $matches);

        if ($matches === []) {
            return [0, 0];
        }

        $column = 0;

        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - ord('A') + 1);
        }

        return [$column - 1, (int) $matches[2] - 1];
    }
}
