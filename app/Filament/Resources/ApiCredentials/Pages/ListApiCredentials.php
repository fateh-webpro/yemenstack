<?php

namespace App\Filament\Resources\ApiCredentials\Pages;

use App\Filament\Resources\ApiCredentials\ApiCredentialResource;
use App\Models\ApiCredential;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

class ListApiCredentials extends ListRecords
{
    protected static string $resource = ApiCredentialResource::class;

    protected ?string $plainToken = null;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->using(function (array $data, string $model): Model {
                    $this->plainToken = ApiCredential::generatePlainToken();
                    $data['token_hash'] = ApiCredential::hashToken($this->plainToken);

                    return $model::create($data);
                })
                ->successNotification(
                    fn (): Notification => Notification::make()
                        ->success()
                        ->persistent()
                        ->title('تم إنشاء مفتاح API')
                        ->body("انسخ هذا المفتاح الآن، لن تتمكن من عرضه مرة أخرى:\n{$this->plainToken}")
                ),
        ];
    }
}