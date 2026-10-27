<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Filament\Resources\Audits\Pages;

use Quansitech\Cmf\Auditing\Filament\Resources\Audits\AuditResource;
use Tapp\FilamentAuditing\Filament\Resources\Audits\Pages\ViewAudit as BaseViewAudit;

class ViewAudit extends BaseViewAudit
{
    protected static string $resource = AuditResource::class;
}
