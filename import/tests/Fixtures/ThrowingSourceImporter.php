<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures;

use Quansitech\Cmf\Import\Template\DropdownSource;

/**
 * 数据源在快照时抛异常的 fixture 导入器：验证失败清单约束装配异常时
 * 降级为纯数据清单（dropdown 仅在模板生成/清单装配期消费，导入流程不受影响）。
 */
class ThrowingSourceImporter extends FixtureImporter
{
    public static function getColumns(): array
    {
        return collect(parent::getColumns())
            ->map(fn ($column) => $column->getName() === 'gender'
                ? $column->dropdown(new class implements DropdownSource
                {
                    public function options(): array
                    {
                        throw new \RuntimeException('数据源不可用');
                    }

                    public function description(): string
                    {
                        return '会失败的数据源';
                    }
                })
                : $column)
            ->all();
    }
}
