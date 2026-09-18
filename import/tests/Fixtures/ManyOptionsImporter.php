<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures;

use Quansitech\Cmf\Import\Template\ConstSource;

/**
 * 大选项量 fixture 导入器：单项下拉 300 项，验证范围引用下拉不被截断
 * （对应全量区划村级 1468 项的场景边界）。
 */
class ManyOptionsImporter extends FixtureImporter
{
    public const OPTION_COUNT = 300;

    public static function getColumns(): array
    {
        return collect(parent::getColumns())
            ->map(fn ($column) => $column->getName() === 'gender'
                ? $column->dropdown(ConstSource::make(
                    array_map(fn (int $i): string => "选项{$i}", range(1, self::OPTION_COUNT)),
                    '大选项量快照',
                ))
                : $column)
            ->all();
    }
}
