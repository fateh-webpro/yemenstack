<?php

namespace App\Filament\Resources\ApiCredentials\Pages;

use App\Filament\Resources\ApiCredentials\ApiCredentialResource;
use App\Models\ApiCredential;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiCredential extends CreateRecord
{
    protected static string $resource = ApiCredentialResource::class;

    protected ?string $plainToken = null;

    protected function handleRecordCreation(array $data): Model
    {
        $this->plainToken = ApiCredential::generatePlainToken();
        $data['token_hash'] = ApiCredential::hashToken($this->plainToken);

        return static::getModel()::create($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->persistent()
            ->title('تم إنشاء مفتاح API')
            ->body("انسخ هذا المفتاح الآن، لن تتمكن من عرضه مرة أخرى:\n{$this->plainToken}");
    }
}