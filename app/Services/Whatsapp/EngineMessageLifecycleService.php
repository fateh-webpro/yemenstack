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
    public function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 50));
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
    public function listPendingMessages(WhatsappAccount $whatsappAccount, int $limit): Collection
    {
        return $this->pendingMessagesQuery($whatsappAccount)
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
                'scheduled_at',
                'created_at',
            ]);
    }

    /**
     * @return Collection<int, Message>
     */
    public function listQueuedMessages(WhatsappAccount $whatsappAccount, int $limit): Collection
    {
        return $this->queuedMessagesQuery($whatsappAccount)
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
    public function claimMessage(WhatsappAccount $whatsappAccount, Message $message): ?array
    {
        return DB::transaction(function () use ($whatsappAccount, $message): ?array {
            $affected = $this->pendingMessagesQuery($whatsappAccount)
                ->whereKey($message->getKey())
                ->update([
                    'status' => Message::STATUS_QUEUED,
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

    protected function pendingMessagesQuery(WhatsappAccount $whatsappAccount): Builder
    {
        return $this->baseScopedQuery($whatsappAccount)
            ->where('status', Message::STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            });
    }

    protected function queuedMessagesQuery(WhatsappAccount $whatsappAccount): Builder
    {
        return $this->baseScopedQuery($whatsappAccount)
            ->where('status', Message::STATUS_QUEUED);
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
}
