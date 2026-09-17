<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures;

use Filament\Actions\Imports\Models\Import;
use Quansitech\Cmf\Import\Template\ConstSource;
use Quansitech\Cmf\Import\Template\XlsxImportColumn;
use Quansitech\Cmf\Import\Tests\Fixtures\Models\FixtureMember;
use Quansitech\Cmf\Import\Xlsx\XlsxImporter;

/**
 * fixture 导入器：列声明覆盖 textLock / dropdown / length，
 * 模拟业务接入形态（继承 XlsxImporter，沿用官方 API）。
 */
class FixtureImporter extends XlsxImporter
{
    protected static ?string $model = FixtureMember::class;

    public static function getColumns(): array
    {
        return [
            XlsxImportColumn::make('name')
                ->label('姓名')
                ->rules(['required', 'max:10'])
                ->textLock()
                ->example('张三'),
            XlsxImportColumn::make('id_card')
                ->label('身份证号')
                ->requiredMappingForNewRecordsOnly()
                ->rules(['required', 'regex:/^\d{18}$/'])
                ->textLock()
                ->length(18, 18)
                ->example('441302199001011234'),
            XlsxImportColumn::make('gender')
                ->label('性别')
                ->requiredMappingForNewRecordsOnly()
                ->rules(['required'])
                ->dropdown(ConstSource::make(['男', '女'], '性别固定选项'))
                ->example('男'),
        ];
    }

    public function resolveRecord(): ?FixtureMember
    {
        return FixtureMember::firstOrNew([
            'id_card' => $this->data['id_card'] ?? null,
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return "成功导入 {$import->successful_rows} 行，失败 {$import->getFailedRowsCount()} 行。";
    }
}
