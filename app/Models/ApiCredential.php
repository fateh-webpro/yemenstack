<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiCredential extends Model
{
    use HasFactory;

    public const TOKEN_PREFIX = 'yswg_';

    protected $fillable = [
        'client_id',
        'whatsapp_account_id',
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->whereDate('expires_at', '>=', today());
    }

    public static function generatePlainToken(): string
    {
        return self::TOKEN_PREFIX . Str::lower(bin2hex(random_bytes(24)));
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return self::query()
            ->where('token_hash', self::hashToken($plainToken))
            ->first();
    }

    public static function abilityOptions(): array
    {
        return [
            'messages:send' => 'messages:send',
            'messages:read' => 'messages:read',
            'accounts:read' => 'accounts:read',
            'webhooks:read' => 'webhooks:read',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }
}