<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    use HasFactory;

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
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
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