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
        'external_message_id',
        'scheduled_at',
        'sent_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
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
            self::DIRECTION_INBOUND => 'وارد',
            self::DIRECTION_OUTBOUND => 'صادر',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'قيد الانتظار',
            self::STATUS_QUEUED => 'في قائمة الانتظار',
            self::STATUS_SENT => 'تم الإرسال',
            self::STATUS_DELIVERED => 'تم التسليم',
            self::STATUS_READ => 'تمت القراءة',
            self::STATUS_FAILED => 'فشل',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_TEXT => 'نص',
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