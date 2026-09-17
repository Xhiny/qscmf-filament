<?php

namespace Quansitech\Cmf\Import;

use Illuminate\Support\ServiceProvider;
use Quansitech\Cmf\Import\Template\Contracts\TemplateGenerator;
use Quansitech\Cmf\Import\Template\PhpSpreadsheetDriver;

class CmfImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TemplateGenerator::class, PhpSpreadsheetDriver::class);
    }
}
