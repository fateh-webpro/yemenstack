<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappAccount;

class WhatsappSessionStartService
{
    public function validationError(WhatsappAccount $whatsappAccount): ?string
    {
        $whatsappAccount->loadMissing('client');

        if (! $whatsappAccount->is_active) {
            return 'WhatsApp account is inactive.';
        }

        if ($whatsappAccount->client === null) {
            return 'WhatsApp account client is missing.';
        }

        if (! $whatsappAccount->client->is_active) {
            return 'WhatsApp account client is inactive.';
        }

        if (! is_string($whatsappAccount->session_name) || preg_match('/^[a-z0-9_]+$/', $whatsappAccount->session_name) !== 1) {
            return 'WhatsApp session name is invalid.';
        }

        return null;
    }

    public function requestStart(WhatsappAccount $whatsappAccount): void
    {
        $whatsappAccount->requestSessionStart();
        $whatsappAccount->refresh();
    }

    /**
     * @return array<string, string|null|int>
     */
    public function responseData(WhatsappAccount $whatsappAccount): array
    {
        return [
            'whatsapp_account_id' => $whatsappAccount->id,
            'session_desired_state' => $whatsappAccount->session_desired_state,
            'status' => $whatsappAccount->status,
            'start_requested_at' => $whatsappAccount->start_requested_at?->toISOString(),
            'stop_requested_at' => $whatsappAccount->stop_requested_at?->toISOString(),
        ];
    }
}