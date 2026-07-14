<?php

namespace App\Filament\Resources\WebhookLogs\Pages;

use App\Filament\Resources\WebhookLogs\WebhookLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookLog extends CreateRecord
{
    protected static string $resource = WebhookLogResource::class;
}
