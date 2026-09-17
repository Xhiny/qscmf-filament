<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Closure;

/**
 * 科学计数法 / 末位截断可疑值的行级检测规则。
 *
 * 可疑值由 CellNormalizer 原样透传进导入流水线，本规则在
 * XlsxImporter::getValidationRules() 中对所有列自动附加，
 * 命中后落入官方 FailedImportRow 行级失败通道。
 */
final class SuspiciousNumericValue
{
    public const FAILURE_MESSAGE = '检测到疑似被 Excel 破坏的数值（科学计数法或精度截断，如 4.41302E+17）：请下载导入模板，在文本锁定列中直接填写，避免手工新建表格输入长数字';

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
