<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineWhatsappAccountStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to update WhatsApp account status.',
            ], 403);
        }

        if (! $whatsappAccount instanceof WhatsappAccount) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp account not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', array_keys(WhatsappAccount::statusLabels()))],
            'qr_expires_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = $validated['status'];
        $lastSeenAt = isset($validated['last_seen_at']) ? Carbon::parse($validated['last_seen_at']) : null;
        $qrExpiresAt = isset($validated['qr_expires_at']) ? Carbon::parse($validated['qr_expires_at']) : null;

        $attributes = [
            'status' => $status,
        ];

        if ($status === WhatsappAccount::STATUS_CONNECTED) {
            $attributes['last_seen_at'] = $lastSeenAt ?? now();
            $attributes['qr_expires_at'] = null;
        } elseif ($status === WhatsappAccount::STATUS_QR_REQUIRED) {
            $attributes['qr_expires_at'] = $qrExpiresAt ?? now()->addMinute();
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

        if (filled($validated['note'] ?? null)) {
            $noteLine = '[' . now()->toDateTimeString() . '] ' . trim($validated['note']);
            $existingNotes = trim((string) $whatsappAccount->notes);
            $attributes['notes'] = $existingNotes === '' ? $noteLine : ($existingNotes . PHP_EOL . $noteLine);
        }

        $whatsappAccount->update($attributes);
        $whatsappAccount->refresh();

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp account status updated.',
            'data' => [
                'whatsapp_account_id' => $whatsappAccount->id,
                'status' => $whatsappAccount->status,
                'last_seen_at' => $whatsappAccount->last_seen_at?->toISOString(),
                'qr_expires_at' => $whatsappAccount->qr_expires_at?->toISOString(),
            ],
        ]);
    }
}