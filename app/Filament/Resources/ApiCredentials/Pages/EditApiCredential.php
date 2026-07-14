<?php

namespace App\Filament\Resources\ApiCredentials\Pages;

use App\Filament\Resources\ApiCredentials\ApiCredentialResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditApiCredential extends EditRecord
{
    protected static string $resource = ApiCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
