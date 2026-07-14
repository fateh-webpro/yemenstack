<?php

namespace App\Filament\Resources\WebhookLogs\Pages;

use App\Filament\Resources\WebhookLogs\WebhookLogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWebhookLog extends ViewRecord
{
    protected static string $resource = WebhookLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
