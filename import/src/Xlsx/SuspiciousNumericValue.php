<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Closure;

/**
 * 科学计数法 / 末位截断可疑值的行级检测规则：可疑值由 CellNormalizer 原样透传，
 * 由 XlsxImporter 对所有列自动附加，命中落入官方 FailedImportRow 行级失败通道。
 */
final class SuspiciousNumericValue
{
    public const FAILURE_MESSAGE = '单元格数值超出 Excel 精度范围，请将该列设为文本格式后用模板重新填写';

    public static function matches(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return CellNormalizer::isSuspicious($value);
    }

    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::matches($value)) {
                $fail(self::FAILURE_MESSAGE);
            }
        };
    }
}
