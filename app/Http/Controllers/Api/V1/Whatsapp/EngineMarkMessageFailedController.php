<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EngineMarkMessageFailedController extends Controller
{
    public function __invoke(Request $request, Message $message): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to mark messages as failed.',
            ], 403);
        }

        if (($message->client_id !== $client->id) || ($message->whatsapp_account_id !== $whatsappAccount->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $validated = $request->validate([
            'error_message' => ['nullable', 'string'],
            'response_payload' => ['nullable', 'array'],
            'failed_at' => ['nullable', 'date'],
            'mode' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
        ]);

        $result = DB::transaction(function () use ($message, $client, $validated): ?array {
            $mode = $validated['mode'] ?? 'simulation';
            $provider = $validated['provider'] ?? ($mode === 'simulation' ? 'local-simulator' : 'whatsapp-web.js');
            $failedAt = filled($validated['failed_at'] ?? null)
                ? Carbon::parse($validated['failed_at'])
                : now();
            $errorMessage = $validated['error_message'] ?? 'Unknown WhatsApp send failure.';

            $affected = Message::query()
                ->where('id', $message->id)
                ->where('client_id', $client->id)
                ->where('whatsapp_account_id', $message->whatsapp_account_id)
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('status', Message::STATUS_QUEUED)
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

            $attempt = MessageAttempt::query()
                ->where('message_id', $message->id)
                ->orderByDesc('attempt_number')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($attempt && ($attempt->status === MessageAttempt::STATUS_QUEUED)) {
                $attempt->forceFill([
                    'status' => MessageAttempt::STATUS_FAILED,
                    'response_payload' => $responsePayload,
                    'error_message' => $errorMessage,
                    'attempted_at' => $attempt->attempted_at ?? $failedAt,
                ])->save();
            } else {
                $attemptNumber = MessageAttempt::query()
                    ->where('message_id', $message->id)
                    ->lockForUpdate()
                    ->count() + 1;

                $attempt = MessageAttempt::query()->create([
                    'message_id' => $message->id,
                    'attempt_number' => $attemptNumber,
                    'status' => MessageAttempt::STATUS_FAILED,
                    'response_payload' => $responsePayload,
                    'error_message' => $errorMessage,
                    'attempted_at' => $failedAt,
                ]);
            }

            $message->refresh();

            return [
                'message' => $message,
                'attempt' => $attempt,
            ];
        });

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Message is not sendable.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message marked as failed.',
            'data' => [
                'message_id' => $result['message']->id,
                'status' => $result['message']->status,
                'failed_at' => $result['message']->failed_at?->toISOString(),
                'attempt_id' => $result['attempt']->id,
                'attempt_status' => $result['attempt']->status,
            ],
        ]);
    }
}