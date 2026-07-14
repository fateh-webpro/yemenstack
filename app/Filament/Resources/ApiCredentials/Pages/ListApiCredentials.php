<?php

namespace App\Filament\Resources\ApiCredentials\Pages;

use App\Filament\Resources\ApiCredentials\ApiCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApiCredentials extends ListRecords
{
    protected static string $resource = ApiCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
