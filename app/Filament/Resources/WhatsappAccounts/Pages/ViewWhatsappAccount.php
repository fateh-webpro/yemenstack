<?php

namespace App\Filament\Resources\WhatsappAccounts\Pages;

use App\Filament\Resources\WhatsappAccounts\WhatsappAccountResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWhatsappAccount extends ViewRecord
{
    protected static string $resource = WhatsappAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
