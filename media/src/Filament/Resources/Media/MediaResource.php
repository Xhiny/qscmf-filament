<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Resources\Media;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Quansitech\Cmf\Media\Filament\Resources\Media\Pages\ListMedia;
use Quansitech\Cmf\Media\Filament\Resources\Media\Pages\ViewMedia;
use Quansitech\Cmf\Media\Filament\Resources\Media\RelationManagers\UsagesRelationManager;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaManager;
use UnitEnum;

/**
 * 后台媒体列表：展示系统全部媒体（含引用计数与引用明细），
 * ref_count>0 的记录禁止删除。
 */
class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $recordTitleAttribute = 'original_name';

    protected static ?string $modelLabel = '媒体';

    protected static ?string $pluralModelLabel = '媒体';

    /**
     * 模型走 config('cmf-media.model')，宿主替换模型后 Resource 自动指向新模型。
     */
    #[Override]
    public static function getModel(): string
    {
        return config('cmf-media.model') ?? parent::getModel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group') === 'filament-shield::filament-shield.nav.group'
            ? '系统'
            : __('filament-shield::filament-shield.nav.group');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('uploader'))
            ->columns(static::getTableColumns())
            ->filters(static::getTableFilters())
            ->recordActions(static::getTableRecordActions());
    }

    /**
     * @return list<Column>
     */
    protected static function getTableColumns(): array
    {
        return [
            ImageColumn::make('thumb')
                ->label('缩略图')
                ->state(fn (Media $record): ?string => $record->thumbUrl())
                ->imageSize(48)
                ->square(),
            TextColumn::make('original_name')
                ->label('名称')
                ->searchable()
                ->limit(30)
                ->tooltip(fn (Media $record): string => $record->original_name),
            TextColumn::make('mime')
                ->label('类型')
                ->badge()
                ->color('gray')
                ->searchable(),
            TextColumn::make('size')
                ->label('大小')
                ->formatStateUsing(fn (int $state): string => static::humanSize($state))
                ->sortable(),
            TextColumn::make('disk')
                ->label('存储')
                ->badge()
                ->color('info'),
            TextColumn::make('ref_count')
                ->label('引用')
                ->badge()
                ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                ->sortable(),
            TextColumn::make('uploader.name')
                ->label('上传人')
                ->placeholder('-'),
            TextColumn::make('created_at')
                ->label('上传时间')
                ->dateTime()
                ->sortable(),
        ];
    }

    /**
     * @return list<SelectFilter|TernaryFilter>
     */
    protected static function getTableFilters(): array
    {
        return [
            SelectFilter::make('disk')
                ->label('存储')
                ->options(fn (): array => collect(MediaManager::configuredDrivers())
                    ->mapWithKeys(fn (string $driver): array => [$driver => $driver])
                    ->all()),
            SelectFilter::make('mime_type')
                ->label('类型')
                ->options([
                    'image' => '图片',
                    'video' => '视频',
                    'audio' => '音频',
                    'other' => '其他',
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'image', 'video', 'audio' => $query->where('mime', 'like', $data['value'].'/%'),
                    'other' => $query->where('mime', 'not like', 'image/%')
                        ->where('mime', 'not like', 'video/%')
                        ->where('mime', 'not like', 'audio/%'),
                    default => $query,
                }),
            TernaryFilter::make('referenced')
                ->label('引用状态')
                ->trueLabel('被引用')
                ->falseLabel('未引用')
                ->queries(
                    true: fn (Builder $query): Builder => $query->where('ref_count', '>', 0),
                    false: fn (Builder $query): Builder => $query->where('ref_count', 0),
                ),
        ];
    }

    /**
     * @return list<\Filament\Actions\Action>
     */
    protected static function getTableRecordActions(): array
    {
        return [
            ViewAction::make(),
            // 仍被引用的媒体不出现删除入口（归零后删除会自动排期清理云端对象）
            DeleteAction::make()
                ->visible(fn (Media $record): bool => $record->ref_count === 0),
        ];
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components(static::getInfolistEntries());
    }

    /**
     * @return list<Component>
     */
    protected static function getInfolistEntries(): array
    {
        return [
            // 按文件类型预览：图片点击放大、视频/音频在线播放、其他类型提供下载
            Section::make('预览')
                ->schema([
                    ViewEntry::make('preview')
                        ->hiddenLabel()
                        ->view('cmf-media::infolists.media-preview'),
                ])
                ->columnSpanFull(),
            Section::make('基本信息')
                ->schema([
                    TextEntry::make('original_name')->label('名称'),
                    TextEntry::make('mime')->label('类型'),
                    TextEntry::make('size')->label('大小')
                        ->formatStateUsing(fn (int $state): string => static::humanSize($state)),
                    TextEntry::make('disk')->label('存储')->badge(),
                    TextEntry::make('path')->label('对象路径')->copyable(),
                    TextEntry::make('hash')->label('内容哈希')->copyable(),
                    TextEntry::make('ref_count')->label('引用计数')->badge(),
                    TextEntry::make('uploader.name')->label('上传人')->placeholder('-'),
                    TextEntry::make('created_at')->label('上传时间')->dateTime(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'view' => ViewMedia::route('/{record}'),
        ];
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
