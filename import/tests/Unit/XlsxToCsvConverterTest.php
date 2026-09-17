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

    public function test_表头行尾部幽灵格式列被裁剪(): void
    {
        // 复刻真实踩坑文件形态：9 列表头 + K2 带格式空单元格 + K 列自定义列宽
        // → 落盘 dimension A1:K2，openspout 按声明宽度把表头行补齐为 11 格
        $builder = XlsxBuilder::make();
        $headers = ['姓名', '身份证号', '性别', '出生日期', '联系电话', '户籍地址', '现居地址', '服务区域', '备注'];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $index => $column) {
            $builder->setText($column.'1', $headers[$index]);
        }
        $builder
            ->setText('A2', '张三')
            ->setText('B2', '441302199001011234');
        $builder->sheet()->getStyle('K2')->getFont()->setBold(true);
        $builder->sheet()->getColumnDimension('K')->setWidth(12);

        $path = $builder->toTempFile();
        $this->assertGhostCellShape($path);

        $rows = $this->convertToRows($path);

        self::assertSame($headers, $rows[0], '带格式无内容的幽灵单元格不得把表头撑出空列');
        self::assertCount(9, $rows[1]);
        self::assertSame('张三', $rows[1][0]);
    }

    /**
     * 固化 fixture 与真实踩坑文件的 dimension/spans 形态：PhpSpreadsheet 写入行为
     * 变化时在此显式失败，而不是静默失去对 openspout 补齐行为的覆盖。
     */
    private function assertGhostCellShape(string $xlsxPath): void
    {
        $zip = new ZipArchive;
        $zip->open($xlsxPath);

        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        self::assertStringContainsString('<dimension ref="A1:K2"/>', $xml);
        self::assertMatchesRegularExpression('/<row r="1"[^>]*spans="1:11"/', $xml);
    }

    public function test_纯空白字符行按空行跳过且单元格值不被_trim(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('B1', '备注')
            ->setText('A2', ' 张三 ')
            ->setText('B2', ' ok ')
            ->setText('A3', ' ')
            ->setText('A4', '　')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        // 半角/全角空白字符行按用户感知视为空行；非空行值保持原样（首尾空白清洗职责在业务校验层）
        self::assertCount(2, $rows);
        self::assertSame([' 张三 ', ' ok '], $rows[1]);
    }

    public function test_首个非空行仅一格内容时提示未找到表头行(): void
    {
        // 标题行形态：若误当表头会顶掉真表头导致数据列整体错位
        $path = XlsxBuilder::make()
            ->setText('A1', '成员导入表')
            ->setText('A2', '姓名')
            ->setText('B2', '身份证号')
            ->setText('A3', '张三')
            ->toTempFile();

        $this->expectException(InvalidXlsxFileException::class);
        $this->expectExceptionMessage('未找到表头行');

        $this->converter->convert($path);
    }

    public function test_表头中间空列标题不参与裁剪_列保持原位(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('D1', '备注')
            ->setText('A2', '张三')
            ->setText('D2', '备注内容')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        // 中间空表头属真歧义（数据行按列位对齐，裁中间列必然错位），由文件级
        // duplicate_columns 校验拒绝，转换层裁剪只针对尾部
        self::assertSame(['姓名', '', '', '备注'], $rows[0]);
        self::assertSame(['张三', '', '', '备注内容'], $rows[1]);
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
            ->setText('B1', '备注')
            ->setText('A2', '张三')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['姓名', '备注'], $rows[0]);
    }

    public function test_数值单元格字符串化且_15_位以内精度完整(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '工号')
            ->setText('B1', '数量')
            ->setNumeric('A2', 123456789012345)
            ->setNumeric('B2', 100)
            ->setNumeric('A3', 99.5)
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['123456789012345', '100'], $rows[1]);
        self::assertSame(['99.5', ''], $rows[2]);
    }

    public function test_超长数值单元格透传科学计数法字样(): void
    {
        // 以 float 写入模拟真实 Excel 行为（Excel 数值单元格即 double 存储）
        $path = XlsxBuilder::make()
            ->setText('A1', '工号')
            ->setText('B1', '备注')
            ->setNumeric('A2', (float) '441302199001011234')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertStringContainsString('E+', $rows[1][0]);
    }

    public function test_文本形态长数字不受影响(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '工号')
            ->setText('B1', '备注')
            ->setText('A2', '441302199001011234')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['441302199001011234', ''], $rows[1]);
    }

    public function test_日期单元格转为_y_m_d_h_i_s(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '登记时间')
            ->setText('B1', '姓名')
            ->setDate('A2', new DateTimeImmutable('2026-01-05 14:30:00'))
            ->setText('B2', '张三')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['2026-01-05 14:30:00', '张三'], $rows[1]);
    }

    public function test_1904_日历日期自动换算(): void
    {
        $path = XlsxBuilder::make()
            ->calendar1904()
            ->setText('A1', '登记时间')
            ->setText('B1', '姓名')
            ->setDate('A2', new DateTimeImmutable('2026-01-05 14:30:00'))
            ->setText('B2', '张三')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        // 1904 日历文件读出的应该是同一个自然时间（openspout 按 date1904 自动 +1462 换算）
        self::assertSame(['2026-01-05 14:30:00', '张三'], $rows[1]);
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
        $builder->setText('B1', '备注');

        $rows = $this->convertToRows($builder->toTempFile());

        self::assertSame(['TRUE', '备注'], $rows[0]);
    }

    public function test_全空行被跳过(): void
    {
        $path = XlsxBuilder::make()
            ->setText('A1', '姓名')
            ->setText('B1', '备注')
            ->setText('A2', '张三')
            ->setText('A4', '李四')
            ->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertCount(3, $rows);
        self::assertSame(['姓名', '备注'], $rows[0]);
        self::assertSame(['张三', ''], $rows[1]);
        self::assertSame(['李四', ''], $rows[2]);
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

    public function test_合并单元格跨三行填充_末行不丢锚点值(): void
    {
        // A2:A4 合并：逐行驱逐上一行锚点的旧实现使第 4 行取不到值（PR #2 评审缺陷 1）
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '部门')
            ->setText('B1', '姓名')
            ->setText('A2', '技术部')
            ->setText('B2', '张三')
            ->setText('B3', '李四')
            ->setText('B4', '王五')
            ->merge('A2:A4');

        $path = $builder->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['技术部', '张三'], $rows[1]);
        self::assertSame(['技术部', '李四'], $rows[2]);
        self::assertSame(['技术部', '王五'], $rows[3], '跨 ≥3 行合并的末行须填充锚点值');
    }

    public function test_多_sheet_固定读第一个(): void
    {
        $builder = XlsxBuilder::make();
        $builder
            ->setText('A1', '第一表头')
            ->setText('B1', '第二表头')
            ->setText('A2', '第一数据');
        $builder->addSheet('第二个sheet')
            ->setCellValue('A1', '第二表头')
            ->setCellValue('A2', '第二数据');

        $path = $builder->toTempFile();

        $rows = $this->convertToRows($path);

        self::assertSame(['第一表头', '第二表头'], $rows[0]);
        self::assertSame(['第一数据', ''], $rows[1]);
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
