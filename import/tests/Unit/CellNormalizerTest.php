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
    public function test_空单元格返回_null(): void
    {
        self::assertNull(CellNormalizer::normalize(Cell::fromValue(null)));
        self::assertNull(CellNormalizer::normalize(Cell::fromValue('')));
    }

    public function test_普通字符串原样返回(): void
    {
        self::assertSame('张三', CellNormalizer::normalize(Cell::fromValue('张三')));
        // 不在归一层 trim（castState 层负责），这里只透传
        self::assertSame('  spaced  ', CellNormalizer::normalize(Cell::fromValue('  spaced  ')));
    }

    public function test_安全区整数精确字符串化_无科学计数法(): void
    {
        self::assertSame('123456789012345', CellNormalizer::normalize(Cell::fromValue(123456789012345)));
        self::assertSame('100', CellNormalizer::normalize(Cell::fromValue(100.0)));
        self::assertSame('0', CellNormalizer::normalize(Cell::fromValue(0.0)));
    }

    public function test_小数数值字符串化(): void
    {
        self::assertSame('0.5', CellNormalizer::normalize(Cell::fromValue(0.5)));
        self::assertSame('99.9', CellNormalizer::normalize(Cell::fromValue(99.9)));
    }

    public function test_合法小数定点输出不触发可疑拒绝(): void
    {
        // |v| < 1e-4 的小数 PHP (string) 会产生科学计数法字样（0.00005 → 5.0E-5），
        // 旧实现在字符串层被误判为可疑值整行拒绝（PR #2 评审缺陷 2）
        $normalized = CellNormalizer::normalize(Cell::fromValue(0.00005));

        self::assertSame('0.00005', $normalized);
        self::assertFalse(CellNormalizer::isSuspicious($normalized));
    }

    public function test_超出安全区的整数透传为科学计数法字样(): void
    {
        // 18 位数字以数值类型存储时 Excel 已截断末位，透传保留科学计数法字样，
        // 交由 SuspiciousNumericValue 行级拒绝
        $normalized = CellNormalizer::normalize(Cell::fromValue(4.4130219901011234E+17));

        self::assertNotNull($normalized);
        self::assertTrue(CellNormalizer::isSuspicious($normalized), "实际归一值: {$normalized}");
    }

    public function test_安全区边界两侧整数判定分流(): void
    {
        // 2^53 - 2：float64 可精确表示且在安全区内 → 定点十进制放行
        $inside = CellNormalizer::normalize(Cell::fromValue((float) '9007199254740990'));

        self::assertSame('9007199254740990', $inside);
        self::assertFalse(CellNormalizer::isSuspicious($inside));

        // 2^53：超出安全区 → 科学计数法字样透传供行级拒绝
        $outside = CellNormalizer::normalize(Cell::fromValue((float) '9007199254740992'));

        self::assertTrue(CellNormalizer::isSuspicious($outside), "实际归一值: {$outside}");
    }

    public function test_日期序列转为_y_m_d_h_i_s_字符串(): void
    {
        self::assertSame(
            '2026-01-05 14:30:00',
            CellNormalizer::normalize(Cell::fromValue(new DateTimeImmutable('2026-01-05 14:30:00'))),
        );
    }

    public function test_时间区间转为_h_i_s_字符串(): void
    {
        self::assertSame(
            '02:30:00',
            CellNormalizer::normalize(Cell::fromValue(new DateInterval('PT2H30M'))),
        );
    }

    public function test_布尔转为_tru_e_false(): void
    {
        self::assertSame('TRUE', CellNormalizer::normalize(Cell::fromValue(true)));
        self::assertSame('FALSE', CellNormalizer::normalize(Cell::fromValue(false)));
    }

    public function test_公式取缓存值(): void
    {
        $formulaWithCache = new FormulaCell('=A1+B1', null, 5);
        $formulaWithFloatCache = new FormulaCell('=A1/B1', null, 2.5);

        self::assertSame('5', CellNormalizer::normalize($formulaWithCache));
        self::assertSame('2.5', CellNormalizer::normalize($formulaWithFloatCache));
    }

    public function test_公式计算缓存为_null_时返回_null(): void
    {
        // xlsx 读取层无缓存公式（<v> 缺失）由 openspout 直接产出数值 0（上游语义，
        // 见 README「已知边界」）；本用例仅锁定 FormulaCell(computedValue=null) 的归一契约
        $formulaWithoutCache = new FormulaCell('=A1+B1', null, null);

        self::assertNull(CellNormalizer::normalize($formulaWithoutCache));
    }

    public function test_科学计数法字样判为可疑(): void
    {
        self::assertTrue(CellNormalizer::isSuspicious('4.41302E+17'));
        self::assertTrue(CellNormalizer::isSuspicious('4.41302e+17'));
        self::assertTrue(CellNormalizer::isSuspicious('-1.23E-5'));
        self::assertTrue(CellNormalizer::isSuspicious('1E+17'));
    }

    public function test_文本形态的_18_位身份证不判为可疑(): void
    {
        // 模板文本锁定列的正确数据形态：长数字文本必须原样通过
        self::assertFalse(CellNormalizer::isSuspicious('441302199001011234'));
        self::assertFalse(CellNormalizer::isSuspicious('张三'));
        self::assertFalse(CellNormalizer::isSuspicious('男'));
        self::assertFalse(CellNormalizer::isSuspicious(null));
        self::assertFalse(CellNormalizer::isSuspicious(''));
    }

    public function test_普通日期文本不判为可疑(): void
    {
        self::assertFalse(CellNormalizer::isSuspicious('2026-01-05'));
        self::assertFalse(CellNormalizer::isSuspicious('2026/01/05 14:30'));
    }
}
