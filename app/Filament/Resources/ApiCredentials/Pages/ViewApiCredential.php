<?php

namespace App\Filament\Resources\ApiCredentials\Pages;

use App\Filament\Resources\ApiCredentials\ApiCredentialResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewApiCredential extends ViewRecord
{
    protected static string $resource = ApiCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
