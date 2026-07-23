<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappAccount extends Model
{
    use HasFactory;

    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_QR_REQUIRED = 'qr_required';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_LOGGED_OUT = 'logged_out';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'client_id',
        'name',
        'phone_number',
        'session_name',
        'status',
        'last_seen_at',
        'qr_expires_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'qr_expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $whatsappAccount): void {
            if (blank($whatsappAccount->session_name)) {
                $whatsappAccount->session_name = self::generateSessionName();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DISCONNECTED => 'غير متصل',
            self::STATUS_QR_REQUIRED => 'يتطلب QR',
            self::STATUS_CONNECTING => 'جارٍ الاتصال',
            self::STATUS_CONNECTED => 'متصل',
            self::STATUS_LOGGED_OUT => 'تم تسجيل الخروج',
            self::STATUS_ERROR => 'خطأ',
        ];
    }

    public static function generateSessionName(): string
    {
        do {
            $sessionName = 'wa_' . bin2hex(random_bytes(12));
        } while (self::query()->where('session_name', $sessionName)->exists());

        return $sessionName;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function pairingTokens(): HasMany
    {
        return $this->hasMany(WhatsappPairingToken::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}