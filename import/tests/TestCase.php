<?php

namespace Quansitech\Cmf\Import\Tests;

use Filament\Actions\ActionsServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use Quansitech\Cmf\Import\CmfImportServiceProvider;
use Quansitech\Cmf\Import\Tests\Fixtures\Models\FixtureUser;

abstract class TestCase extends TestbenchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // filament Import::user() 回退查找 App\Models\User；testbench 无该类，
        // 用 fixture 模型别名以解析 user 关系
        if (! class_exists('App\Models\User')) {
            class_alias(FixtureUser::class, 'App\Models\User');
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            ActionsServiceProvider::class,
            CmfImportServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // 文件型 sqlite（phpunit-safe 要求 test_ 前缀库名），每个测试先清空旧 schema
        $connection = Schema::getConnection();
        $tables = $connection->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'",
        );

        foreach ($tables as $table) {
            Schema::dropIfExists($table->name);
        }

        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/filament/actions/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
