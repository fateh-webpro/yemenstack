<?php

namespace App\Filament\Resources\WhatsappAccounts\Pages;

use App\Filament\Resources\WhatsappAccounts\WhatsappAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWhatsappAccount extends EditRecord
{
    protected static string $resource = WhatsappAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
