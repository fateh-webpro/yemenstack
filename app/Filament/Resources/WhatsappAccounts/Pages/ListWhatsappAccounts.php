<?php

namespace App\Filament\Resources\WhatsappAccounts\Pages;

use App\Filament\Resources\WhatsappAccounts\WhatsappAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappAccounts extends ListRecords
{
    protected static string $resource = WhatsappAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
