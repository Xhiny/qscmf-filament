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

                if (! $this->isBlank($value)) {
                    $isBlankRow = false;
                }
            }

            // 合并单元格取左上值：非锚点格子填充锚点值
            foreach ($mergeMap[$rowIndex] ?? [] as $columnIndex => $anchor) {
                [$anchorRow, $anchorColumn] = $anchor;

                $values[$columnIndex] = $anchorRow === $rowIndex
                    ? ($values[$anchorColumn] ?? null)
                    : ($anchorValues[$anchorRow][$anchorColumn] ?? null);

                if (! $this->isBlank($values[$columnIndex])) {
                    $isBlankRow = false;
                }
            }

            foreach ($anchorCells[$rowIndex] ?? [] as $columnIndex => $true) {
                $anchorValues[$rowIndex][$columnIndex] = $values[$columnIndex] ?? null;
            }

            if ($isBlankRow) {
                continue;
            }

            if ($headerColumnCount === null) {
                // openspout 按 xlsx 声明宽度（dimension/spans）补齐行：带格式无内容的
                // 幽灵单元格 / 列宽残留会把表头行撑出尾部空列，故定宽前先裁尾。
                // 被裁掉的幻列本就是 unmapped 忽略语义，数据无损
                $values = $this->trimTrailingBlankCells($values);

                // 有效表头列数不足 2（如标题行误置首行）：不嗅探会把标题行当表头，
                // 数据列整体错位，故显式报错引导
                if (count(array_filter($values, fn (?string $value): bool => ! $this->isBlank($value))) < 2) {
                    $reader->close();

                    throw new InvalidXlsxFileException('未找到表头行，请保留模板首行表头后重试');
                }

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
     * 用户感知的空行/空格判定：Unicode 空白（含全角空格）trim 后判空。
     * 仅用于判空，不改写单元格原值（首尾空白清洗职责在各 Importer 业务校验层）。
     */
    private function isBlank(?string $value): bool
    {
        return mb_trim((string) $value) === '';
    }

    /**
     * 裁掉尾部空白格（含带格式无内容的幽灵格式格），中间列位不动。
     *
     * @param  array<int, string|null>  $values
     * @return array<int, string|null>
     */
    private function trimTrailingBlankCells(array $values): array
    {
        while ($values !== []) {
            $lastColumnIndex = array_key_last($values);

            if (! $this->isBlank($values[$lastColumnIndex])) {
                break;
            }

            unset($values[$lastColumnIndex]);
        }

        return $values;
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
