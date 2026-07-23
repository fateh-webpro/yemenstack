<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsappPairingToken extends Model
{
    use HasFactory;

    public const TOKEN_PREFIX = 'yspair_';

    protected $fillable = [
        'whatsapp_account_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public static function generatePlainToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(24));
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return self::query()
            ->with(['whatsappAccount.client', 'createdBy'])
            ->where('token_hash', self::hashToken($plainToken))
            ->first();
    }

    public static function revokeUsableForWhatsappAccount(WhatsappAccount $whatsappAccount, ?Carbon $revokedAt = null): int
    {
        $revokedAt ??= now();

        return DB::transaction(function () use ($whatsappAccount, $revokedAt): int {
            return self::query()
                ->where('whatsapp_account_id', $whatsappAccount->getKey())
                ->usable()
                ->lockForUpdate()
                ->update([
                    'revoked_at' => $revokedAt,
                    'updated_at' => $revokedAt,
                ]);
        });
    }

    /**
     * @return array{0:string,1:self}
     */
    public static function issueForWhatsappAccount(
        WhatsappAccount $whatsappAccount,
        Carbon $expiresAt,
        ?int $createdBy = null,
        ?array $metadata = null,
    ): array {
        return DB::transaction(function () use ($whatsappAccount, $expiresAt, $createdBy, $metadata): array {
            self::revokeUsableForWhatsappAccount($whatsappAccount);

            do {
                $plainToken = self::generatePlainToken();
                $tokenHash = self::hashToken($plainToken);
            } while (self::query()->where('token_hash', $tokenHash)->exists());

            $pairingToken = self::query()->create([
                'whatsapp_account_id' => $whatsappAccount->getKey(),
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'revoked_at' => null,
                'created_by' => $createdBy,
                'metadata' => $metadata,
            ]);

            return [$plainToken, $pairingToken];
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        $this->loadMissing('whatsappAccount.client');

        return ! $this->isUsed()
            && ! $this->isRevoked()
            && ! $this->isExpired()
            && $this->whatsappAccount !== null
            && $this->whatsappAccount->is_active
            && $this->whatsappAccount->client !== null
            && $this->whatsappAccount->client->is_active;
    }

    public function markAsUsed(?Carbon $usedAt = null): void
    {
        $this->forceFill([
            'used_at' => $usedAt ?? now(),
        ])->saveQuietly();
    }

    public function revoke(?Carbon $revokedAt = null): void
    {
        $this->forceFill([
            'revoked_at' => $revokedAt ?? now(),
        ])->saveQuietly();
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}