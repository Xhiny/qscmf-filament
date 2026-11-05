<?php

namespace Quansitech\Cmf\Import\Tests\Unit;

use Quansitech\Cmf\Import\Template\ConstSource;
use Quansitech\Cmf\Import\Template\DropdownSource;
use Quansitech\Cmf\Import\Template\QuerySource;
use Quansitech\Cmf\Import\Tests\TestCase;

class DropdownSourceTest extends TestCase
{
    public function test_const_source_implements_contract_and_returns_options(): void
    {
        $source = ConstSource::make(['男', '女']);

        self::assertInstanceOf(DropdownSource::class, $source);
        self::assertSame(['男', '女'], $source->options());
        self::assertNotSame('', $source->description());
    }

    public function test_query_source_implements_contract_and_snapshots_options(): void
    {
        $source = QuerySource::make(fn (): array => collect(['广州', '深圳'])->all());

        self::assertInstanceOf(DropdownSource::class, $source);
        self::assertSame(['广州', '深圳'], $source->options());
    }

    public function test_query_source_caps_options_at_2000(): void
    {
        $options = array_map(fn (int $i): string => "选项{$i}", range(1, 2100));

        $source = QuerySource::make(fn (): array => $options);

        self::assertCount(2000, $source->options());
    }
}
