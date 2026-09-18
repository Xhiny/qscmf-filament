<?php

namespace Quansitech\Cmf\Import\Tests\Feature;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Quansitech\Cmf\Import\Template\Contracts\TemplateGenerator;
use Quansitech\Cmf\Import\Template\PhpSpreadsheetDriver;
use Quansitech\Cmf\Import\Template\TemplateDownload;
use Quansitech\Cmf\Import\Tests\Fixtures\FixtureImporter;
use Quansitech\Cmf\Import\Tests\Fixtures\ManyOptionsImporter;
use Quansitech\Cmf\Import\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateDownloadTest extends TestCase
{
    public function test_template_download_response(): void
    {
        $response = TemplateDownload::downloadResponse(FixtureImporter::class);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        self::assertSame('PK', substr($bytes, 0, 2));

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-download-');
        file_put_contents($path, $bytes);

        $sheet = IOFactory::load($path)->getSheet(0);

        unlink($path);

        self::assertSame('姓名', $sheet->getCell('A1')->getValue());
    }

    public function test_container_binds_php_spreadsheet_as_default_driver(): void
    {
        self::assertInstanceOf(
            PhpSpreadsheetDriver::class,
            app(TemplateGenerator::class),
        );
    }

    public function test_dropdown_options_over_255_are_not_truncated(): void
    {
        $response = TemplateDownload::downloadResponse(ManyOptionsImporter::class);

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-many-options-');
        file_put_contents($path, $bytes);

        $options = IOFactory::load($path)->getSheetByName('_options');
        unlink($path);

        $values = [];
        $row = 1;

        while (filled($value = $options->getCell("A{$row}")->getValue())) {
            $values[] = (string) $value;
            $row++;
        }

        self::assertSame(ManyOptionsImporter::OPTION_COUNT, count($values), '范围引用下拉不受旧 255 上限截断');
        self::assertSame('选项300', $values[299]);
    }
}
