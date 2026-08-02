<?php

namespace App\Services\Whatsapp;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageAttempt;
use App\Models\WhatsappAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EngineMessageLifecycleService
{
    public const CLAIM_MODE_MANUAL = 'manual';
    public const CLAIM_MODE_AUTOMATIC = 'automatic';

    public function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 50));
    }

    public function normalizeClaimMode(?string $mode): string
    {
        return match ($mode) {
            self::CLAIM_MODE_AUTOMATIC => self::CLAIM_MODE_AUTOMATIC,
            default => self::CLAIM_MODE_MANUAL,
        };
    }

    public function processingAccountError(WhatsappAccount $whatsappAccount): ?string
    {
        $client = $whatsappAccount->client;

        if (! $whatsappAccount->is_active) {
            return 'WhatsApp account is inactive.';
        }

        if (! $client instanceof Client) {
            return 'WhatsApp account client is missing.';
        }

        if (! $client->is_active) {
            return 'WhatsApp account client is inactive.';
        }

        return null;
    }

    /**
     * @return Collection<int, Message>
     */
    public function listPendingMessages(WhatsappAccount $whatsappAccount, int $limit, ?string $mode = null): Collection
    {
        return $this->pendingMessagesQuery($whatsappAccount, $mode)
            ->orderBy('id')
            ->limit($this->normalizeLimit($limit))
            ->get([
                'id',
                'recipient',
                'sender',
                'message_type',
                'body',
                'payload',
                'status',
                'manual_send_requested',
                'scheduled_at',
                'created_at',
            ]);
    }

    /**
     * @return Collection<int, Message>
     */
    public function listQueuedMessages(WhatsappAccount $whatsappAccount, int $limit, ?string $mode = null): Collection
    {
        return $this->queuedMessagesQuery($whatsappAccount, $mode)
            ->orderBy('id')
            ->limit($this->normalizeLimit($limit))
            ->get([
                'id',
                'recipient',
                'sender',
                'message_type',
                'body',
                'payload',
                'status',
                'manual_send_requested',
                'created_at',
                'updated_at',
            ]);
    }

    public function messageBelongsToAccount(WhatsappAccount $whatsappAccount, Message $message): bool
    {
        return $this->baseScopedQuery($whatsappAccount)
            ->whereKey($message->getKey())
            ->exists();
    }

    /**
     * @return array{message: Message, attempt: MessageAttempt}|null
     */
    public function claimMessage(WhatsappAccount $whatsappAccount, Message $message, ?string $mode = null): ?array
    {
        $claimMode = $this->normalizeClaimMode($mode);

        if ($claimMode === self::CLAIM_MODE_AUTOMATIC && ! $whatsappAccount->automaticSendingEnabled()) {
            return null;
        }

        if ($claimMode === self::CLAIM_MODE_MANUAL && ! $message->manual_send_requested) {
            return null;
        }

        return DB::transaction(function () use ($whatsappAccount, $message, $claimMode): ?array {
            return $this->claimPendingMessageWithinTransaction($whatsappAccount, $message, $claimMode);
        });
    }

    /**
     * @return array{ok: bool, code: string, message: string, record: Message|null, attempt: MessageAttempt|null}
     */
    public function requestManualMessageSend(WhatsappAccount $whatsappAccount, Message $message): array
    {
        if (! $this->messageBelongsToAccount($whatsappAccount, $message)) {
            return [
                'ok' => false,
                'code' => 'not_found',
                'message' => 'Message not found.',
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($error = $this->processingAccountError($whatsappAccount)) {
            return [
                'ok' => false,
                'code' => 'account_invalid',
                'message' => $error,
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($whatsappAccount->automaticSendingEnabled()) {
            return [
                'ok' => false,
                'code' => 'automatic_enabled',
                'message' => 'Automatic sending is enabled for this WhatsApp account.',
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($whatsappAccount->status !== WhatsappAccount::STATUS_CONNECTED) {
            return [
                'ok' => false,
                'code' => 'session_not_connected',
                'message' => 'WhatsApp account is not connected.',
                'record' => null,
                'attempt' => null,
            ];
        }

        return DB::transaction(function () use ($whatsappAccount, $message): array {
            /** @var Message|null $lockedMessage */
            $lockedMessage = $this->baseScopedQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedMessage) {
                return [
                    'ok' => false,
                    'code' => 'not_found',
                    'message' => 'Message not found.',
                    'record' => null,
                    'attempt' => null,
                ];
            }

            if (in_array($lockedMessage->status, [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ], true)) {
                return [
                    'ok' => false,
                    'code' => 'already_sent',
                    'message' => 'Message was already sent.',
                    'record' => $lockedMessage,
                    'attempt' => null,
                ];
            }

            if ($lockedMessage->status === Message::STATUS_FAILED) {
                return [
                    'ok' => false,
                    'code' => 'requires_retry',
                    'message' => 'Failed messages must be returned to pending before manual send.',
                    'record' => $lockedMessage,
                    'attempt' => null,
                ];
            }

            if ($lockedMessage->status === Message::STATUS_QUEUED) {
                if ($lockedMessage->manual_send_requested) {
                    return [
                        'ok' => false,
                        'code' => 'already_requested',
                        'message' => 'Manual send was already requested for this message.',
                        'record' => $lockedMessage,
                        'attempt' => $this->latestAttempt($lockedMessage),
                    ];
                }

                $lockedMessage->forceFill([
                    'manual_send_requested' => true,
                    'updated_at' => now(),
                ])->save();

                $lockedMessage->refresh();

                return [
                    'ok' => true,
                    'code' => 'queued_for_manual_send',
                    'message' => 'Message queued for manual send.',
                    'record' => $lockedMessage,
                    'attempt' => $this->latestAttempt($lockedMessage),
                ];
            }

            if ($lockedMessage->status !== Message::STATUS_PENDING) {
                return [
                    'ok' => false,
                    'code' => 'not_sendable',
                    'message' => 'Message is not sendable.',
                    'record' => $lockedMessage,
                    'attempt' => null,
                ];
            }

            $result = $this->claimPendingMessageWithinTransaction($whatsappAccount, $lockedMessage, self::CLAIM_MODE_MANUAL);

            if (! $result) {
                return [
                    'ok' => false,
                    'code' => 'not_claimable',
                    'message' => 'Message is not claimable.',
                    'record' => $lockedMessage,
                    'attempt' => null,
                ];
            }

            return [
                'ok' => true,
                'code' => 'queued_for_manual_send',
                'message' => 'Message queued for manual send.',
                'record' => $result['message'],
                'attempt' => $result['attempt'],
            ];
        });
    }

    /**
     * @return array{ok: bool, code: string, message: string, record: Message|null, attempt: MessageAttempt|null}
     */
    public function retryFailedMessageSend(WhatsappAccount $whatsappAccount, Message $message): array
    {
        if (! $this->messageBelongsToAccount($whatsappAccount, $message)) {
            return [
                'ok' => false,
                'code' => 'not_found',
                'message' => 'Message not found.',
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($error = $this->processingAccountError($whatsappAccount)) {
            return [
                'ok' => false,
                'code' => 'account_invalid',
                'message' => $error,
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($whatsappAccount->automaticSendingEnabled()) {
            return [
                'ok' => false,
                'code' => 'automatic_enabled',
                'message' => 'Automatic sending is enabled for this WhatsApp account.',
                'record' => null,
                'attempt' => null,
            ];
        }

        if ($whatsappAccount->status !== WhatsappAccount::STATUS_CONNECTED) {
            return [
                'ok' => false,
                'code' => 'session_not_connected',
                'message' => 'WhatsApp account is not connected.',
                'record' => null,
                'attempt' => null,
            ];
        }

        return DB::transaction(function () use ($whatsappAccount, $message): array {
            /** @var Message|null $lockedMessage */
            $lockedMessage = $this->baseScopedQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedMessage) {
                return [
                    'ok' => false,
                    'code' => 'not_found',
                    'message' => 'Message not found.',
                    'record' => null,
                    'attempt' => null,
                ];
            }

            if ($lockedMessage->status !== Message::STATUS_FAILED) {
                return [
                    'ok' => false,
                    'code' => 'not_failed',
                    'message' => 'Message is not failed.',
                    'record' => $lockedMessage,
                    'attempt' => $this->latestAttempt($lockedMessage),
                ];
            }

            $lockedMessage->forceFill([
                'status' => Message::STATUS_PENDING,
                'manual_send_requested' => false,
                'failed_at' => null,
                'error_message' => null,
                'sent_at' => null,
                'external_message_id' => null,
            ])->save();

            $lockedMessage->refresh();

            $result = $this->claimPendingMessageWithinTransaction($whatsappAccount, $lockedMessage, self::CLAIM_MODE_MANUAL);

            if (! $result) {
                return [
                    'ok' => false,
                    'code' => 'not_claimable',
                    'message' => 'Message is not claimable.',
                    'record' => $lockedMessage,
                    'attempt' => null,
                ];
            }

            return [
                'ok' => true,
                'code' => 'queued_for_manual_send',
                'message' => 'Message queued for manual send.',
                'record' => $result['message'],
                'attempt' => $result['attempt'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{message: Message, attempt: MessageAttempt}|null
     */
    public function deferQueuedMessage(WhatsappAccount $whatsappAccount, Message $message, array $validated = []): ?array
    {
        return DB::transaction(function () use ($whatsappAccount, $message, $validated): ?array {
            $deferredAt = filled($validated['deferred_at'] ?? null)
                ? Carbon::parse($validated['deferred_at'])
                : now();

            $responsePayload = $validated['response_payload'] ?? [
                'mode' => $validated['mode'] ?? 'real',
                'provider' => $validated['provider'] ?? 'whatsapp-web.js',
                'error_message' => $validated['error_message'] ?? null,
                'stage' => $validated['stage'] ?? null,
                'note' => 'Message deferred for session recovery.',
                'deferred_at' => $deferredAt->toISOString(),
            ];

            $affected = $this->queuedMessagesQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->update([
                    'status' => Message::STATUS_PENDING,
                    'updated_at' => $deferredAt,
                ]);

            if ($affected === 0) {
                return null;
            }

            $attempt = $this->updateOrCreateAttempt(
                message: $message,
                status: MessageAttempt::STATUS_PENDING,
                attemptedAt: $deferredAt,
                responsePayload: $responsePayload,
                errorMessage: null,
            );

            $message->refresh();

            return [
                'message' => $message,
                'attempt' => $attempt,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{message: Message, attempt: MessageAttempt}|null
     */
    public function markMessageSent(WhatsappAccount $whatsappAccount, Message $message, array $validated): ?array
    {
        return DB::transaction(function () use ($whatsappAccount, $message, $validated): ?array {
            $mode = $validated['mode'] ?? 'simulation';
            $provider = $validated['provider'] ?? ($mode === 'simulation' ? 'local-simulator' : 'whatsapp-web.js');
            $sentAt = filled($validated['sent_at'] ?? null)
                ? Carbon::parse($validated['sent_at'])
                : now();
            $externalMessageId = $validated['external_message_id']
                ?? sprintf('simulated-%d-%d', $message->id, now()->timestamp);

            $affected = $this->queuedMessagesQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->update([
                    'status' => Message::STATUS_SENT,
                    'manual_send_requested' => false,
                    'sent_at' => $sentAt,
                    'external_message_id' => $externalMessageId,
                    'updated_at' => $sentAt,
                ]);

            if ($affected === 0) {
                return null;
            }

            $responsePayload = $validated['response_payload'] ?? [
                'mode' => $mode,
                'provider' => $provider,
                'external_message_id' => $externalMessageId,
                'sent_at' => $sentAt->toISOString(),
                'note' => $mode === 'simulation'
                    ? 'No real WhatsApp message was sent.'
                    : 'Message marked as sent by WhatsApp engine.',
            ];

            $attempt = $this->updateOrCreateAttempt(
                message: $message,
                status: MessageAttempt::STATUS_SENT,
                attemptedAt: $sentAt,
                responsePayload: $responsePayload,
                errorMessage: null,
            );

            $message->refresh();

            return [
                'message' => $message,
                'attempt' => $attempt,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{message: Message, attempt: MessageAttempt}|null
     */
    public function markMessageFailed(WhatsappAccount $whatsappAccount, Message $message, array $validated): ?array
    {
        return DB::transaction(function () use ($whatsappAccount, $message, $validated): ?array {
            $mode = $validated['mode'] ?? 'simulation';
            $provider = $validated['provider'] ?? ($mode === 'simulation' ? 'local-simulator' : 'whatsapp-web.js');
            $failedAt = filled($validated['failed_at'] ?? null)
                ? Carbon::parse($validated['failed_at'])
                : now();
            $errorMessage = $validated['error_message'] ?? 'Unknown WhatsApp send failure.';

            $affected = $this->queuedMessagesQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->update([
                    'status' => Message::STATUS_FAILED,
                    'manual_send_requested' => false,
                    'failed_at' => $failedAt,
                    'error_message' => $errorMessage,
                    'updated_at' => $failedAt,
                ]);

            if ($affected === 0) {
                return null;
            }

            $responsePayload = $validated['response_payload'] ?? [
                'mode' => $mode,
                'provider' => $provider,
                'error_message' => $errorMessage,
                'failed_at' => $failedAt->toISOString(),
            ];

            $attempt = $this->updateOrCreateAttempt(
                message: $message,
                status: MessageAttempt::STATUS_FAILED,
                attemptedAt: $failedAt,
                responsePayload: $responsePayload,
                errorMessage: $errorMessage,
            );

            $message->refresh();

            return [
                'message' => $message,
                'attempt' => $attempt,
            ];
        });
    }

    protected function baseScopedQuery(WhatsappAccount $whatsappAccount): Builder
    {
        return Message::query()
            ->where('client_id', $whatsappAccount->client_id)
            ->where('whatsapp_account_id', $whatsappAccount->id)
            ->where('direction', Message::DIRECTION_OUTBOUND);
    }

    protected function pendingMessagesQuery(WhatsappAccount $whatsappAccount, ?string $mode = null): Builder
    {
        $query = $this->baseScopedQuery($whatsappAccount)
            ->where('status', Message::STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            });

        if ($mode === self::CLAIM_MODE_MANUAL) {
            $query->where('manual_send_requested', true);
        }

        return $query;
    }

    protected function queuedMessagesQuery(WhatsappAccount $whatsappAccount, ?string $mode = null): Builder
    {
        $query = $this->baseScopedQuery($whatsappAccount)
            ->where('status', Message::STATUS_QUEUED);

        if ($mode === self::CLAIM_MODE_AUTOMATIC && ! $whatsappAccount->automaticSendingEnabled()) {
            $query->whereRaw('1 = 0');
        }

        if ($mode === self::CLAIM_MODE_MANUAL) {
            $query->where('manual_send_requested', true);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    protected function updateOrCreateAttempt(
        Message $message,
        string $status,
        Carbon $attemptedAt,
        ?array $responsePayload,
        ?string $errorMessage,
    ): MessageAttempt {
        $attempt = MessageAttempt::query()
            ->where('message_id', $message->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($attempt && ($attempt->status === MessageAttempt::STATUS_QUEUED)) {
            $attempt->forceFill([
                'status' => $status,
                'response_payload' => $responsePayload,
                'error_message' => $errorMessage,
                'attempted_at' => $attempt->attempted_at ?? $attemptedAt,
            ])->save();

            return $attempt;
        }

        $attemptNumber = MessageAttempt::query()
            ->where('message_id', $message->id)
            ->lockForUpdate()
            ->count() + 1;

        return MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'response_payload' => $responsePayload,
            'error_message' => $errorMessage,
            'attempted_at' => $attemptedAt,
        ]);
    }

    /**
     * @return array{message: Message, attempt: MessageAttempt}|null
     */
    protected function claimPendingMessageWithinTransaction(WhatsappAccount $whatsappAccount, Message $message, string $mode): ?array
    {
        $affected = $this->pendingMessagesQuery($whatsappAccount)
            ->whereKey($message->getKey())
            ->update([
                'status' => Message::STATUS_QUEUED,
                'manual_send_requested' => $mode === self::CLAIM_MODE_MANUAL,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return null;
        }

        $attemptNumber = MessageAttempt::query()
            ->where('message_id', $message->id)
            ->lockForUpdate()
            ->count() + 1;

        $attempt = MessageAttempt::query()->create([
            'message_id' => $message->id,
            'attempt_number' => $attemptNumber,
            'status' => MessageAttempt::STATUS_QUEUED,
            'response_payload' => null,
            'error_message' => null,
            'attempted_at' => now(),
        ]);

        $message->refresh();

        return [
            'message' => $message,
            'attempt' => $attempt,
        ];
    }

    protected function latestAttempt(Message $message): ?MessageAttempt
    {
        return MessageAttempt::query()
            ->where('message_id', $message->id)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->first();
    }
}