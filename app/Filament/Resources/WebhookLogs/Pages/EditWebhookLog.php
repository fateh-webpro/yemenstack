<?php

namespace App\Filament\Resources\WebhookLogs\Pages;

use App\Filament\Resources\WebhookLogs\WebhookLogResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookLog extends EditRecord
{
    protected static string $resource = WebhookLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
