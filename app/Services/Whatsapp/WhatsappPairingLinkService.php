<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappAccount;
use App\Models\WhatsappPairingToken;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsappPairingLinkService
{
    public function __construct(
        protected WhatsappPairingQrCache $pairingQrCache,
    ) {}

    /**
     * @return array{plain_token:string,pairing_url:string,pairing_token:WhatsappPairingToken}
     */
    public function issueLink(WhatsappAccount $whatsappAccount, int $expiresInMinutes, ?int $createdBy = null): array
    {
        $whatsappAccount->loadMissing('client');

        if (! $whatsappAccount->is_active) {
            throw ValidationException::withMessages([
                'whatsapp_account' => 'الحساب غير نشط حاليًا.',
            ]);
        }

        if ($whatsappAccount->client === null || ! $whatsappAccount->client->is_active) {
            throw ValidationException::withMessages([
                'whatsapp_account' => 'العميل المرتبط بهذا الحساب غير نشط حاليًا.',
            ]);
        }

        [$plainToken, $pairingToken] = WhatsappPairingToken::issueForWhatsappAccount(
            $whatsappAccount,
            now()->addMinutes($expiresInMinutes),
            $createdBy,
            ['source' => 'filament']
        );

        return [
            'plain_token' => $plainToken,
            'pairing_url' => route('whatsapp.pair.show', ['token' => $plainToken]),
            'pairing_token' => $pairingToken->fresh(['createdBy']),
        ];
    }

    public function revokeLink(WhatsappAccount $whatsappAccount): int
    {
        $revokedCount = WhatsappPairingToken::revokeUsableForWhatsappAccount($whatsappAccount);

        try {
            $this->pairingQrCache->forget($whatsappAccount);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $revokedCount;
    }
}