<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappAccount;
use Carbon\Carbon;

class EngineWhatsappAccountStatusService
{
    /**
     * @return array<string, array<int, string>>
     */
    public function legacyValidationRules(): array
    {
        return [
            'status' => ['required', 'string', 'in:' . implode(',', array_keys(WhatsappAccount::statusLabels()))],
            'qr_expires_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function centralValidationRules(): array
    {
        return [
            'status' => ['required', 'string', 'in:' . implode(',', $this->centralStatuses())],
            'qr_expires_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'error_code' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function update(WhatsappAccount $whatsappAccount, array $validated): array
    {
        $status = (string) $validated['status'];
        $lastSeenAt = isset($validated['last_seen_at']) ? Carbon::parse($validated['last_seen_at']) : null;
        $qrExpiresAt = isset($validated['qr_expires_at']) ? Carbon::parse($validated['qr_expires_at']) : null;

        $attributes = [
            'status' => $status,
        ];

        if (filled($validated['phone_number'] ?? null)) {
            $attributes['phone_number'] = trim((string) $validated['phone_number']);
        }

        if ($status === WhatsappAccount::STATUS_CONNECTED) {
            $attributes['last_seen_at'] = $lastSeenAt ?? now();
            $attributes['qr_expires_at'] = null;
        } elseif ($status === WhatsappAccount::STATUS_QR_REQUIRED) {
            $attributes['qr_expires_at'] = $qrExpiresAt ?? now()->addMinute();

            if ($lastSeenAt) {
                $attributes['last_seen_at'] = $lastSeenAt;
            }
        } elseif ($status === 'authenticated') {
            $attributes['qr_expires_at'] = null;

            if ($lastSeenAt) {
                $attributes['last_seen_at'] = $lastSeenAt;
            }
        } else {
            if ($lastSeenAt) {
                $attributes['last_seen_at'] = $lastSeenAt;
            }

            if ($qrExpiresAt) {
                $attributes['qr_expires_at'] = $qrExpiresAt;
            } elseif (in_array($status, [
                WhatsappAccount::STATUS_DISCONNECTED,
                WhatsappAccount::STATUS_LOGGED_OUT,
                WhatsappAccount::STATUS_ERROR,
            ], true)) {
                $attributes['qr_expires_at'] = null;
            }
        }

        if ($note = $this->buildNote($validated)) {
            $noteLine = '[' . now()->toDateTimeString() . '] ' . $note;
            $existingNotes = trim((string) $whatsappAccount->notes);
            $attributes['notes'] = $existingNotes === '' ? $noteLine : ($existingNotes . PHP_EOL . $noteLine);
        }

        $whatsappAccount->update($attributes);
        $whatsappAccount->refresh();

        return [
            'whatsapp_account_id' => $whatsappAccount->id,
            'status' => $whatsappAccount->status,
            'phone_number' => $whatsappAccount->phone_number,
            'last_seen_at' => $whatsappAccount->last_seen_at?->toISOString(),
            'qr_expires_at' => $whatsappAccount->qr_expires_at?->toISOString(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function centralStatuses(): array
    {
        return array_values(array_unique([
            ...array_keys(WhatsappAccount::statusLabels()),
            'authenticated',
        ]));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function buildNote(array $validated): ?string
    {
        $parts = [];

        if (filled($validated['note'] ?? null)) {
            $parts[] = trim((string) $validated['note']);
        }

        if (filled($validated['reason'] ?? null)) {
            $parts[] = 'reason: ' . trim((string) $validated['reason']);
        }

        if (filled($validated['error_code'] ?? null)) {
            $parts[] = 'error_code: ' . trim((string) $validated['error_code']);
        }

        if (filled($validated['error_message'] ?? null)) {
            $parts[] = 'error_message: ' . trim((string) $validated['error_message']);
        }

        if ($parts === []) {
            return null;
        }

        return implode(' | ', $parts);
    }
}