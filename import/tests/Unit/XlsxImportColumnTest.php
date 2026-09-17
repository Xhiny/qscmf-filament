<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use Filament\Actions\Imports\ImportColumn;
use Quansitech\Cmf\Import\Template\ConstSource;
use Quansitech\Cmf\Import\Template\QuerySource;
use Quansitech\Cmf\Import\Template\XlsxImportColumn;
use Quansitech\Cmf\Import\Tests\TestCase;

class XlsxImportColumnTest extends TestCase
{
    public function test_继承官方_import_column_的既有_ap_i_不丢失(): void
    {
        $column = XlsxImportColumn::make('id_card')
            ->label('身份证号')
            ->rules(['required']);

        self::assertInstanceOf(ImportColumn::class, $column);
        self::assertSame('身份证号', $column->getLabel());
        self::assertSame(['required'], $column->getDataValidationRules());
    }

    public function test_text_lock_默认关闭_开启后可读取(): void
    {
        $column = XlsxImportColumn::make('id_card');

        self::assertFalse($column->isTextLocked());

        self::assertTrue($column->textLock()->isTextLocked());
    }

    public function test_length_单参为精确长度(): void
    {
        $column = XlsxImportColumn::make('id_card')->length(18);

        self::assertSame(18, $column->getMinLength());
        self::assertSame(18, $column->getMaxLength());
    }

    public function test_length_双参为区间(): void
    {
        $column = XlsxImportColumn::make('name')->length(2, 10);

        self::assertSame(2, $column->getMinLength());
        self::assertSame(10, $column->getMaxLength());
    }

    public function test_length_仅上限(): void
    {
        $column = XlsxImportColumn::make('remark')->length(null, 200);

        self::assertNull($column->getMinLength());
        self::assertSame(200, $column->getMaxLength());
    }

    public function test_length_支持闭包惰性求值(): void
    {
        $column = XlsxImportColumn::make('id_card')->length(fn (): int => 18);

        self::assertSame(18, $column->getMinLength());
    }

    public function test_dropdown_声明数据源(): void
    {
        $source = ConstSource::make(['男', '女']);
        $column = XlsxImportColumn::make('gender')->dropdown($source);

        self::assertSame($source, $column->getDropdownSource());
    }

    public function test_未声明模板校验的列各取值默认(): void
    {
        $column = XlsxImportColumn::make('remark');

        self::assertFalse($column->isTextLocked());
        self::assertNull($column->getMinLength());
        self::assertNull($column->getMaxLength());
        self::assertNull($column->getDropdownSource());
    }

    public function test_query_source_快照求值(): void
    {
        $evaluated = false;
        $source = QuerySource::make(function () use (&$evaluated): array {
            $evaluated = true;

            return ['广州', '深圳'];
        });

        self::assertFalse($evaluated, 'options() 调用前不应执行查询闭包');
        self::assertSame(['广州', '深圳'], $source->options());
        self::assertTrue($evaluated);
    }
}
