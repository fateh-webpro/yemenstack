<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngineMarkMessageSentController extends Controller
{
    public function __invoke(Request $request, Message $message): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to mark messages as sent.',
            ], 403);
        }

        if (($message->client_id !== $client->id) || ($message->whatsapp_account_id !== $whatsappAccount->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $result = DB::transaction(function () use ($message, $client, $whatsappAccount): ?array {
            $simulatedExternalId = sprintf('simulated-%d-%d', $message->id, now()->timestamp);
            $sentAt = now();

            $affected = Message::query()
                ->where('id', $message->id)
                ->where('client_id', $client->id)
                ->where('whatsapp_account_id', $whatsappAccount->id)
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('status', Message::STATUS_QUEUED)
                ->update([
                    'status' => Message::STATUS_SENT,
                    'sent_at' => $sentAt,
                    'external_message_id' => $simulatedExternalId,
                    'updated_at' => $sentAt,
                ]);

            if ($affected === 0) {
                return null;
            }

            $responsePayload = [
                'mode' => 'simulation',
                'provider' => 'local-simulator',
                'external_message_id' => $simulatedExternalId,
                'sent_at' => $sentAt->toISOString(),
                'note' => 'No real WhatsApp message was sent.',
            ];

            $attempt = MessageAttempt::query()
                ->where('message_id', $message->id)
                ->orderByDesc('attempt_number')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($attempt && ($attempt->status === MessageAttempt::STATUS_QUEUED)) {
                $attempt->forceFill([
                    'status' => MessageAttempt::STATUS_SENT,
                    'response_payload' => $responsePayload,
                    'error_message' => null,
                    'attempted_at' => $attempt->attempted_at ?? $sentAt,
                ])->save();
            } else {
                $attemptNumber = MessageAttempt::query()
                    ->where('message_id', $message->id)
                    ->lockForUpdate()
                    ->count() + 1;

                $attempt = MessageAttempt::query()->create([
                    'message_id' => $message->id,
                    'attempt_number' => $attemptNumber,
                    'status' => MessageAttempt::STATUS_SENT,
                    'response_payload' => $responsePayload,
                    'error_message' => null,
                    'attempted_at' => $sentAt,
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
            'message' => 'Message marked as sent in simulation mode.',
            'data' => [
                'message_id' => $result['message']->id,
                'status' => $result['message']->status,
                'external_message_id' => $result['message']->external_message_id,
                'sent_at' => $result['message']->sent_at?->toISOString(),
                'attempt_id' => $result['attempt']->id,
                'attempt_status' => $result['attempt']->status,
            ],
        ]);
    }
}