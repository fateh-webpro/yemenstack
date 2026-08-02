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
            self::DIRECTION_INBOUND => "\u{0648}\u{0627}\u{0631}\u{062F}\u{0629}",
            self::DIRECTION_OUTBOUND => "\u{0635}\u{0627}\u{062F}\u{0631}\u{0629}",
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => "\u{0642}\u{064A}\u{062F} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631}",
            self::STATUS_QUEUED => "\u{0641}\u{064A} \u{0642}\u{0627}\u{0626}\u{0645}\u{0629} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631}",
            self::STATUS_SENT => "\u{062A}\u{0645} \u{0627}\u{0644}\u{0625}\u{0631}\u{0633}\u{0627}\u{0644}",
            self::STATUS_DELIVERED => "\u{062A}\u{0645} \u{0627}\u{0644}\u{062A}\u{0633}\u{0644}\u{064A}\u{0645}",
            self::STATUS_READ => "\u{062A}\u{0645}\u{062A} \u{0627}\u{0644}\u{0642}\u{0631}\u{0627}\u{0621}\u{0629}",
            self::STATUS_FAILED => "\u{0641}\u{0634}\u{0644}",
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_TEXT => "\u{0646}\u{0635}",
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