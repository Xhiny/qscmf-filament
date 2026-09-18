<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use Quansitech\Cmf\Import\Tests\TestCase;
use Quansitech\Cmf\Import\Xlsx\CellNormalizer;

class CellNormalizerTest extends TestCase
{
    public function test_empty_cell_returns_null(): void
    {
        self::assertNull(CellNormalizer::normalize(Cell::fromValue(null)));
        self::assertNull(CellNormalizer::normalize(Cell::fromValue('')));
    }

    public function test_plain_string_returned_as_is(): void
    {
        self::assertSame('张三', CellNormalizer::normalize(Cell::fromValue('张三')));
        // 不在归一层 trim（castState 层负责），这里只透传
        self::assertSame('  spaced  ', CellNormalizer::normalize(Cell::fromValue('  spaced  ')));
    }

    public function test_safe_zone_integer_stringified_exactly_without_scientific_notation(): void
    {
        self::assertSame('123456789012345', CellNormalizer::normalize(Cell::fromValue(123456789012345)));
        self::assertSame('100', CellNormalizer::normalize(Cell::fromValue(100.0)));
        self::assertSame('0', CellNormalizer::normalize(Cell::fromValue(0.0)));
    }

    public function test_decimal_number_stringified(): void
    {
        self::assertSame('0.5', CellNormalizer::normalize(Cell::fromValue(0.5)));
        self::assertSame('99.9', CellNormalizer::normalize(Cell::fromValue(99.9)));
    }

    public function test_tiny_decimal_fixed_point_output_not_flagged_suspicious(): void
    {
        // |v| < 1e-4 的小数 PHP (string) 会产生科学计数法字样（0.00005 → 5.0E-5），
        // 旧实现在字符串层被误判为可疑值整行拒绝
        $normalized = CellNormalizer::normalize(Cell::fromValue(0.00005));

        self::assertSame('0.00005', $normalized);
        self::assertFalse(CellNormalizer::isSuspicious($normalized));
    }

    public function test_out_of_safe_zone_integer_passed_through_as_scientific_notation(): void
    {
        // 18 位数字以数值类型存储时 Excel 已截断末位，透传保留科学计数法字样，
        // 交由 SuspiciousNumericValue 行级拒绝
        $normalized = CellNormalizer::normalize(Cell::fromValue(4.4130219901011234E+17));

        self::assertNotNull($normalized);
        self::assertTrue(CellNormalizer::isSuspicious($normalized), "实际归一值: {$normalized}");
    }

    public function test_safe_zone_boundary_integers_branch_on_both_sides(): void
    {
        // 2^53 - 2：float64 可精确表示且在安全区内 → 定点十进制放行
        $inside = CellNormalizer::normalize(Cell::fromValue((float) '9007199254740990'));

        self::assertSame('9007199254740990', $inside);
        self::assertFalse(CellNormalizer::isSuspicious($inside));

        // 2^53：超出安全区 → 科学计数法字样透传供行级拒绝
        $outside = CellNormalizer::normalize(Cell::fromValue((float) '9007199254740992'));

        self::assertTrue(CellNormalizer::isSuspicious($outside), "实际归一值: {$outside}");
    }

    public function test_date_serial_converted_to_datetime_string(): void
    {
        self::assertSame(
            '2026-01-05 14:30:00',
            CellNormalizer::normalize(Cell::fromValue(new DateTimeImmutable('2026-01-05 14:30:00'))),
        );
    }

    public function test_time_interval_converted_to_his_string(): void
    {
        self::assertSame(
            '02:30:00',
            CellNormalizer::normalize(Cell::fromValue(new DateInterval('PT2H30M'))),
        );
    }

    public function test_boolean_converted_to_true_false(): void
    {
        self::assertSame('TRUE', CellNormalizer::normalize(Cell::fromValue(true)));
        self::assertSame('FALSE', CellNormalizer::normalize(Cell::fromValue(false)));
    }

    public function test_formula_uses_cached_value(): void
    {
        $formulaWithCache = new FormulaCell('=A1+B1', null, 5);
        $formulaWithFloatCache = new FormulaCell('=A1/B1', null, 2.5);

        self::assertSame('5', CellNormalizer::normalize($formulaWithCache));
        self::assertSame('2.5', CellNormalizer::normalize($formulaWithFloatCache));
    }

    public function test_formula_with_null_cached_value_returns_null(): void
    {
        // xlsx 读取层无缓存公式（<v> 缺失）由 openspout 直接产出数值 0（上游语义，
        // 见 README「已知边界」）；本用例仅锁定 FormulaCell(computedValue=null) 的归一契约
        $formulaWithoutCache = new FormulaCell('=A1+B1', null, null);

        self::assertNull(CellNormalizer::normalize($formulaWithoutCache));
    }

    public function test_scientific_notation_string_flagged_suspicious(): void
    {
        self::assertTrue(CellNormalizer::isSuspicious('4.41302E+17'));
        self::assertTrue(CellNormalizer::isSuspicious('4.41302e+17'));
        self::assertTrue(CellNormalizer::isSuspicious('-1.23E-5'));
        self::assertTrue(CellNormalizer::isSuspicious('1E+17'));
    }

    public function test_text_form_18_digit_id_card_not_flagged_suspicious(): void
    {
        // 模板文本锁定列的正确数据形态：长数字文本必须原样通过
        self::assertFalse(CellNormalizer::isSuspicious('441302199001011234'));
        self::assertFalse(CellNormalizer::isSuspicious('张三'));
        self::assertFalse(CellNormalizer::isSuspicious('男'));
        self::assertFalse(CellNormalizer::isSuspicious(null));
        self::assertFalse(CellNormalizer::isSuspicious(''));
    }

    public function test_plain_date_text_not_flagged_suspicious(): void
    {
        self::assertFalse(CellNormalizer::isSuspicious('2026-01-05'));
        self::assertFalse(CellNormalizer::isSuspicious('2026/01/05 14:30'));
    }
}
