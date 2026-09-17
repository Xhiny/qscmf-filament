<?php

namespace Quansitech\Cmf\Import\Template;

/**
 * 模板下拉数据源契约。选项一律写入模板隐藏 _options 工作表，
 * 以范围引用承载（规避内联列表 255 字符限制与逗号转义问题）。
 */
interface DropdownSource
{
    /**
     * 模板生成时调用的选项快照。
     *
     * @return array<int, string>
     */
    public function options(): array;

    /**
     * 写入填写说明 sheet 的数据源描述。
     */
    public function description(): string;
}
