<?php

namespace Quansitech\Cmf\Import\Template;

/**
 * 常量列表数据源（如性别：男 / 女）。
 */
class ConstSource implements DropdownSource
{
    /**
     * @param  array<int, string>  $options
     */
    public function __construct(
        protected array $options,
        protected string $description = '固定选项列表',
    ) {}

    /**
     * @param  array<int, string>  $options
     */
    public static function make(array $options, string $description = '固定选项列表'): static
    {
        return new static($options, $description);
    }

    public function options(): array
    {
        return array_values(array_map(strval(...), $this->options));
    }

    public function description(): string
    {
        return $this->description;
    }
}
