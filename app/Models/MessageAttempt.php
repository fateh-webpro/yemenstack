<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttempt extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_id',
        'attempt_number',
        'status',
        'response_payload',
        'error_message',
        'attempted_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'attempted_at' => 'datetime',
    ];

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'قيد الانتظار',
            self::STATUS_QUEUED => 'في قائمة الانتظار',
            self::STATUS_SENT => 'تم الإرسال',
            self::STATUS_FAILED => 'فشل',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}