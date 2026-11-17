<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Resources\Media\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;
use Quansitech\Cmf\Media\Models\Media;

class ViewMedia extends ViewRecord
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Media $record): bool => $record->ref_count === 0),
        ];
    }
}
