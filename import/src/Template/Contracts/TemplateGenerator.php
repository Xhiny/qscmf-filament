<?php

namespace Quansitech\Cmf\Import\Template\Contracts;

use Filament\Actions\Imports\Importer;

/**
 * 模板生成驱动接口。默认实现为 PhpSpreadsheetDriver；
 * 将来 filament 放开 openspout ^5 后可切换原生 DataValidation 驱动。
 */
interface TemplateGenerator
{
    /**
     * 由 Importer 的 getColumns() 驱动生成 xlsx 模板。
     *
     * @param  class-string<Importer>  $importerClass
     * @return string 生成的 xlsx 临时文件路径（调用方负责展示 / 清理）
     */
    public function generate(string $importerClass): string;
}
