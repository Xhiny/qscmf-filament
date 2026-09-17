<?php

namespace Quansitech\Cmf\Import\Template;

use Filament\Actions\Imports\Importer;
use Quansitech\Cmf\Import\Template\Contracts\TemplateGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 模板生成 / 下载能力出口：由 Importer getColumns() 驱动，返回可下载响应。
 * 不提供 UI 按钮——模板下载由接入方内嵌于导入界面组装（单一入口原则）。
 */
final class TemplateDownload
{
    /**
     * @param  class-string<Importer>  $importerClass
     */
    public static function downloadResponse(string $importerClass, ?string $fileName = null): StreamedResponse
    {
        $generator = app(TemplateGenerator::class);

        return response()->streamDownload(function () use ($generator, $importerClass): void {
            $tempFilePath = $generator->generate($importerClass);

            readfile($tempFilePath);
            @unlink($tempFilePath);
        }, $fileName ?? self::defaultFileName($importerClass), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private static function defaultFileName(string $importerClass): string
    {
        return (string) str(class_basename($importerClass))->beforeLast('Importer')->kebab().'-导入模板.xlsx';
    }
}
