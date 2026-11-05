<?php

namespace Quansitech\Cmf\Import\Xlsx;

use Filament\Actions\Imports\Downloaders\Contracts\Downloader;
use Filament\Actions\Imports\Importer;

/**
 * xlsx 导入基类：业务侧以本类替代官方 Importer 作为父类，官方 API 零迁移。
 * 对所有列自动附加可疑值检测规则（命中落入官方 FailedImportRow 行级失败通道，
 * 中文原因），并默认覆写失败清单下载器为 xlsx 输出。
 */
abstract class XlsxImporter extends Importer
{
    /**
     * @return array<string, array<mixed>>
     */
    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();

        foreach ($rules as $columnName => $columnRules) {
            $rules[$columnName][] = SuspiciousNumericValue::rule();
        }

        return $rules;
    }

    public static function getFailedRowsDownloader(): Downloader
    {
        return app(XlsxFailedRowsDownloader::class);
    }
}
