<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class WhatsappPairingQrCache
{
    public function put(WhatsappAccount $whatsappAccount, string $qr, ?CarbonInterface $expiresAt = null): CarbonInterface
    {
        $resolvedExpiresAt = $expiresAt?->copy() ?? now()->addSeconds($this->ttlSeconds());

        Cache::put($this->keyFor($whatsappAccount), $qr, $resolvedExpiresAt);

        return $resolvedExpiresAt;
    }

    public function get(WhatsappAccount $whatsappAccount): ?string
    {
        $value = Cache::get($this->keyFor($whatsappAccount));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function forget(WhatsappAccount $whatsappAccount): void
    {
        Cache::forget($this->keyFor($whatsappAccount));
    }

    public function keyFor(WhatsappAccount|int|string $whatsappAccount): string
    {
        $accountId = $whatsappAccount instanceof WhatsappAccount ? $whatsappAccount->getKey() : $whatsappAccount;

        return 'whatsapp:pairing-qr:' . (string) $accountId;
    }

    public function ttlSeconds(): int
    {
        $ttl = (int) config('services.whatsapp_engine.pairing_qr_cache_ttl_seconds', 90);

        return $ttl > 0 ? $ttl : 90;
    }
}