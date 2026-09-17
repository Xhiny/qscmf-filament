<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use DateTimeImmutable;
use Quansitech\Cmf\Import\Tests\Fixtures\XlsxBuilder;
use Quansitech\Cmf\Import\Tests\TestCase;
use Quansitech\Cmf\Import\Xlsx\InvalidXlsxFileException;
use Quansitech\Cmf\Import\Xlsx\XlsxToCsvConverter;
use ZipArchive;

/**
 * 归一规则矩阵回归（任务 5.2 的转换器层）：科学计数法 / 截断 / 日期 / 1904 日历 /
 * 公式 / 合并单元格 / 空行 / 多 sheet / 伪 xlsx。
 */
class XlsxToCsvConverterTest extends TestCase
{
    private XlsxToCsvConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new XlsxToCsvConverter;
    }

    /**
     * @return array<array<int, ?string>>
     */
    private function convertToRows(string $xlsxPath): array
    {
        $stream = $this->converter->convert($xlsxPath);

        $rows = [];

        while (($line = fgetcsv($stream)) !== false) {
            $rows[] = $line;
        }

        fclose($stream);

        return $rows;
    }

    public function test_表头与文本数据原样输出(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('B1', '身份证号')
            ->setText('A2', '张三')
            ->setText('B2', '441302199001011234')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['姓名', '身份证号'], $rows[0]);
        self::assertSame(['张三', '441302199001011234'], $rows[1]);
    }

    public function test_表头首尾空白被剔除以保证自动映射(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', ' 姓名 ')
            ->setText('A2', '张三')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['姓名'], $rows[0]);
    }

    public function test_数值单元格字符串化且_15_位以内精度完整(): void
    {
        $path = XlsxBuilder::make()
            ->setNumeric('A1', 123456789012345)
            ->setNumeric('A2', 100)
            ->setNumeric('A3', 99.5)
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['123456789012345'], $rows[0]);
        self::assertSame(['100'], $rows[1]);
        self::assertSame(['99.5'], $rows[2]);
    }

    public function test_超长数值单元格透传科学计数法字样(): void
    {
        // 以 float 写入模拟真实 Excel 行为（Excel 数值单元格即 double 存储）
        $path = XlsxBuilder::make()
            ->setNumeric('A1', (float) '441302199001011234')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertStringContainsString('E+', $rows[0][0]);
    }

    public function test_文本形态长数字不受影响(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '441302199001011234')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['441302199001011234'], $rows[0]);
    }

    public function test_日期单元格转为_y_m_d_h_i_s(): void
    {
        $path = XlsxBuilder::make()
            ->setDate('A1', new DateTimeImmutable('2026-01-05 14:30:00'))
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['2026-01-05 14:30:00'], $rows[0]);
    }

    public function test_1904_日历日期自动换算(): void
    {
        $path = XlsxBuilder::make()
            ->calendar1904()
            ->setDate('A1', new DateTimeImmutable('2026-01-05 14:30:00'))
            ->toTempFile();

        $rows = $this->convertToRows($path);

        // 1904 日历文件读出的应该是同一个自然时间（openspout 按 date1904 自动 +1462 换算）
        self::assertSame(['2026-01-05 14:30:00'], $rows[0]);
    }

    public function test_公式取缓存值_无缓存按_0_处理(): void
    {
        $path = XlsxBuilder::make()
            ->setNumeric('A1', 1)
            ->setNumeric('B1', 2)
            ->setFormula('C1', '=A1+B1', 3)
            ->setFormulaWithoutCache('D1', '=A1*100')
            ->toTempFile();

        // 模拟无缓存公式：移除 D1 的 <v> 节点（Excel/WPS 保存的真实文件必含缓存值）
        $this->stripCellCachedValue($path, 'D1');

        $rows = $this->convertToRows($path);

        // 边界说明：openspout 把无缓存公式的空 <v> 数值化为 0，
        // 该上游语义记录于 README「已知边界」
        self::assertSame(['1', '2', '3', '0'], $rows[0]);
    }

    private function stripCellCachedValue(string $xlsxPath, string $cellReference): void
    {
        $zip = new ZipArchive;
        $zip->open($xlsxPath);

        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = preg_replace(
            sprintf('/(<c r="%s"[^>]*>.*?)<v>[^<]*<\/v>(.*?<\/c>)/s', $cellReference),
            '$1$2',
            $xml,
            1,
        );

        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
    }

    public function test_布尔单元格转为_tru_e_false(): void
    {
        $builder = XlsxBuilder::make();
        $builder->sheet()->setCellValue('A1', true);

        $rows = $this->convertToRows($builder->toTempFile());

        self::assertSame(['TRUE'], $rows[0]);
    }

    public function test_全空行被跳过(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('A2', '张三')
            ->setText('A4', '李四')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertCount(3, $rows);
        self::assertSame(['姓名'], $rows[0]);
        self::assertSame(['张三'], $rows[1]);
        self::assertSame(['李四'], $rows[2]);
    }

    public function test_数据行长度自动对齐表头列数(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('B1', '性别')
            ->setText('C1', '备注')
            ->setText('A2', '张三')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['张三', '', ''], $rows[1]);
    }

    public function test_合并单元格同行取左上值(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '姓名')
            ->setText('B1', '备注')
            ->setText('C1', '说明')
            ->setText('A2', '张三')
            ->setText('B2', '兼职工')
            ->merge('B2:C2');

        $path = $builder->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['姓名', '备注', '说明'], $rows[0]);
        self::assertSame(['张三', '兼职工', '兼职工'], $rows[1]);
    }

    public function test_合并单元格跨行取左上值(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '部门')
            ->setText('B1', '姓名')
            ->setText('A2', '技术部')
            ->setText('B2', '张三')
            ->setText('B3', '李四')
            ->merge('A2:A3');

        $path = $builder->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['技术部', '张三'], $rows[1]);
        self::assertSame(['技术部', '李四'], $rows[2]);
    }

    public function test_多_sheet_固定读第一个(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '第一表头')
            ->setText('A2', '第一数据');
        $builder->addSheet('第二个sheet')
            ->setCellValue('A1', '第二表头')
            ->setCellValue('A2', '第二数据');

        $path = $builder->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['第一表头'], $rows[0]);
        self::assertSame(['第一数据'], $rows[1]);
    }

    public function test_伪_xlsx_抛业务友好异常(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fake-xlsx-');
        file_put_contents($path, 'id,name'.PHP_EOL.'1,张三');

        $this->expectException(InvalidXlsxFileException::class);
        $this->expectExceptionMessage('不是有效的 xlsx');

        $this->converter->convert($path);
    }

    public function test_空文件抛业务友好异常(): void
    {
        $path = XlsxBuilder::make()->toTempFile();

        $this->expectException(InvalidXlsxFileException::class);

        $this->converter->convert($path);
    }
}
