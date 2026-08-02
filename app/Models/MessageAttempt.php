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
            self::STATUS_PENDING => "\u{0642}\u{064A}\u{062F} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631}",
            self::STATUS_QUEUED => "\u{0641}\u{064A} \u{0642}\u{0627}\u{0626}\u{0645}\u{0629} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631}",
            self::STATUS_SENT => "\u{062A}\u{0645} \u{0627}\u{0644}\u{0625}\u{0631}\u{0633}\u{0627}\u{0644}",
            self::STATUS_FAILED => "\u{0641}\u{0634}\u{0644}",
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}