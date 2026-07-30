<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatsappAccount extends Model
{
    use HasFactory;

    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_QR_REQUIRED = 'qr_required';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_AUTHENTICATED = 'authenticated';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_LOGGED_OUT = 'logged_out';
    public const STATUS_ERROR = 'error';

    public const SESSION_DESIRED_RUNNING = 'running';
    public const SESSION_DESIRED_STOPPED = 'stopped';

    protected $fillable = [
        'client_id',
        'name',
        'phone_number',
        'session_name',
        'session_desired_state',
        'automatic_sending_enabled',
        'start_requested_at',
        'stop_requested_at',
        'status',
        'last_seen_at',
        'qr_expires_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'start_requested_at' => 'datetime',
        'stop_requested_at' => 'datetime',
        'automatic_sending_enabled' => 'boolean',
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

            if (blank($whatsappAccount->session_desired_state)) {
                $whatsappAccount->session_desired_state = self::SESSION_DESIRED_STOPPED;
            }

            if ($whatsappAccount->automatic_sending_enabled === null) {
                $whatsappAccount->automatic_sending_enabled = false;
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
            self::STATUS_DISCONNECTED => 'ط·ط›ط¸ظ¹ط·آ± ط¸â€¦ط·ع¾ط·آµط¸â€‍',
            self::STATUS_QR_REQUIRED => 'ط·آ¨ط·آ§ط¸â€ ط·ع¾ط·آ¸ط·آ§ط·آ± ط¸â€¦ط·آ³ط·آ­ ط·آ±ط¸â€¦ط·آ² QR',
            self::STATUS_CONNECTING => 'ط·آ¬ط·آ§ط·آ±ط¸ع† ط·آ§ط¸â€‍ط·آ§ط·ع¾ط·آµط·آ§ط¸â€‍',
            self::STATUS_AUTHENTICATED => 'ط·ع¾ط¸â€¦ ط·آ§ط¸â€‍ط·ع¾ط·آ­ط¸â€ڑط¸â€ڑ ط¸â€¦ط¸â€  ط·آ§ط¸â€‍ط·آ­ط·آ³ط·آ§ط·آ¨',
            self::STATUS_CONNECTED => 'ط¸â€¦ط·ع¾ط·آµط¸â€‍',
            self::STATUS_LOGGED_OUT => 'ط·ع¾ط¸â€¦ ط·ع¾ط·آ³ط·آ¬ط¸ظ¹ط¸â€‍ ط·آ§ط¸â€‍ط·آ®ط·آ±ط¸ث†ط·آ¬',
            self::STATUS_ERROR => 'ط·آ®ط·آ·ط·آ£ ط¸ظ¾ط¸ظ¹ ط·آ§ط¸â€‍ط·آ§ط·ع¾ط·آµط·آ§ط¸â€‍',
        ];
    }

    public static function desiredStateLabels(): array
    {
        return [
            self::SESSION_DESIRED_RUNNING => 'ط¸â€¦ط·آ·ط¸â€‍ط¸ث†ط·آ¨ ط·آ§ط¸â€‍ط·ع¾ط·آ´ط·ط›ط¸ظ¹ط¸â€‍',
            self::SESSION_DESIRED_STOPPED => 'ط¸â€¦ط·ع¾ط¸ث†ط¸â€ڑط¸ظ¾ ط·آ¥ط·آ¯ط·آ§ط·آ±ط¸ظ¹ط¸â€¹ط·آ§',
        ];
    }

    public static function generateSessionName(): string
    {
        do {
            $sessionName = 'wa_' . bin2hex(random_bytes(12));
        } while (self::query()->where('session_name', $sessionName)->exists());

        return $sessionName;
    }

    public function requestSessionStart(): void
    {
        if ($this->wantsSessionRunning()) {
            return;
        }

        $this->forceFill([
            'session_desired_state' => self::SESSION_DESIRED_RUNNING,
            'start_requested_at' => now(),
            'stop_requested_at' => null,
        ])->saveQuietly();
    }

    public function requestSessionStop(): void
    {
        if ($this->wantsSessionStopped()) {
            return;
        }

        $this->forceFill([
            'session_desired_state' => self::SESSION_DESIRED_STOPPED,
            'stop_requested_at' => now(),
        ])->saveQuietly();
    }

    public function wantsSessionRunning(): bool
    {
        return $this->session_desired_state === self::SESSION_DESIRED_RUNNING;
    }

    public function wantsSessionStopped(): bool
    {
        return $this->session_desired_state === self::SESSION_DESIRED_STOPPED;
    }

    public function automaticSendingEnabled(): bool
    {
        return (bool) $this->automatic_sending_enabled;
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

    public function latestPairingToken(): HasOne
    {
        return $this->hasOne(WhatsappPairingToken::class)->latestOfMany();
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