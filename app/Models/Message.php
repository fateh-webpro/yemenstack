<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'client_id',
        'whatsapp_account_id',
        'direction',
        'recipient',
        'sender',
        'message_type',
        'body',
        'payload',
        'status',
        'manual_send_requested',
        'external_message_id',
        'scheduled_at',
        'sent_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'manual_send_requested' => 'boolean',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public static function directionLabels(): array
    {
        return [
            self::DIRECTION_INBOUND => 'ط¸ث†ط·آ§ط·آ±ط·آ¯',
            self::DIRECTION_OUTBOUND => 'ط·آµط·آ§ط·آ¯ط·آ±',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'ط¸â€ڑط¸ظ¹ط·آ¯ ط·آ§ط¸â€‍ط·آ§ط¸â€ ط·ع¾ط·آ¸ط·آ§ط·آ±',
            self::STATUS_QUEUED => 'ط¸ظ¾ط¸ظ¹ ط¸â€ڑط·آ§ط·آ¦ط¸â€¦ط·آ© ط·آ§ط¸â€‍ط·آ§ط¸â€ ط·ع¾ط·آ¸ط·آ§ط·آ±',
            self::STATUS_SENT => 'ط·ع¾ط¸â€¦ ط·آ§ط¸â€‍ط·آ¥ط·آ±ط·آ³ط·آ§ط¸â€‍',
            self::STATUS_DELIVERED => 'ط·ع¾ط¸â€¦ ط·آ§ط¸â€‍ط·ع¾ط·آ³ط¸â€‍ط¸ظ¹ط¸â€¦',
            self::STATUS_READ => 'ط·ع¾ط¸â€¦ط·ع¾ ط·آ§ط¸â€‍ط¸â€ڑط·آ±ط·آ§ط·طŒط·آ©',
            self::STATUS_FAILED => 'ط¸ظ¾ط·آ´ط¸â€‍',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_TEXT => 'ط¸â€ ط·آµ',
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

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class);
    }
}