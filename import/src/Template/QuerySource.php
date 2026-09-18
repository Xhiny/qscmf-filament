<?php

namespace Quansitech\Cmf\Import\Template;

use Closure;

/**
 * 数据库查询数据源：模板生成时执行闭包，结果快照为下拉选项（隐藏 sheet 范围
 * 引用，不受内联列表 255 字符限制）；上限 2000 行防模板失控，超出截断。
 */
class QuerySource implements DropdownSource
{
    protected const MAX_OPTIONS = 2000;

    /**
     * @param  Closure(): array<int, string>  $query
     */
    public function __construct(
        protected Closure $query,
        protected string $description = '数据库查询快照（生成模板时）',
    ) {}

    public static function make(Closure $query, string $description = '数据库查询快照（生成模板时）'): static
    {
        return new static($query, $description);
    }

    public function options(): array
    {
        $options = array_values(array_map(strval(...), (array) ($this->query)()));

        return array_slice($options, 0, self::MAX_OPTIONS);
    }

    public function description(): string
    {
        return $this->description;
    }
}
