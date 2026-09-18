<?php

namespace Quansitech\Cmf\Import\Xlsx;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;

/**
 * 单元格归一器：把 openspout 读出的 Cell 转为字符串值（specs/xlsx-import「单元格归一与脏数据拒绝」）。
 * 数字精确字符串化——安全区（|v| < 2^53）无科学计数法；超区长整数 Excel 保存端已截断，
 * 以科学计数法字样透传交由 XlsxImporter 行级拒绝；日期 → Y-m-d H:i:s（1904 日历由
 * openspout 按 date1904 自动换算）；公式取缓存值，无缓存按 0（openspout 上游语义）；
 * 空单元格 → null（整行全空时由转换器跳过该行）。
 */
final class CellNormalizer
{
    /**
     * 科学计数法字样（含 Excel 保存时截断产生的长整数透传形态）。
     */
    public const SUSPICIOUS_VALUE_PATTERN = '/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/';

    public static function normalize(Cell $cell): ?string
    {
        if ($cell instanceof EmptyCell) {
            return null;
        }

        if ($cell instanceof FormulaCell) {
            return self::fromScalar($cell->getComputedValue());
        }

        return self::fromScalar($cell->getValue());
    }

    private static function fromScalar(bool|DateInterval|DateTimeInterface|float|int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof DateInterval) {
            return $value->format('%H:%I:%S');
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value)) {
            // 超出 float64 安全区的大整数同样在保存端不可信，与 float 走同一编码
            return self::fromFloat((float) $value);
        }

        if (is_float($value)) {
            return self::fromFloat($value);
        }

        return $value;
    }

    private static function fromFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return (string) $value;
        }

        if (floor($value) === $value) {
            // 整数安全区：float64 可精确表示（15 位以内完整保持），输出纯十进制
            if (abs($value) < (2 ** 53)) {
                return number_format($value, 0, '.', '');
            }

            // 超出安全区：Excel 保存端已截断，保留科学计数法字样透传
            return (string) $value;
        }

        // 非整数定点输出：PHP (string) 对 |v| < 1e-4 的小数会产生科学计数法字样，
        // 会在字符串层被可疑值机制误判整行拒绝
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }

    public static function isSuspicious(?string $normalizedValue): bool
    {
        if ($normalizedValue === null || $normalizedValue === '') {
            return false;
        }

        return preg_match(self::SUSPICIOUS_VALUE_PATTERN, $normalizedValue) === 1;
    }
}
