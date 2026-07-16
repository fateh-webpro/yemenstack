<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngineClaimMessageController extends Controller
{
    public function __invoke(Request $request, Message $message): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to claim messages.',
            ], 403);
        }

        if (($message->client_id !== $client->id) || ($message->whatsapp_account_id !== $whatsappAccount->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $result = DB::transaction(function () use ($message, $client, $whatsappAccount): ?array {
            $affected = Message::query()
                ->where('id', $message->id)
                ->where('client_id', $client->id)
                ->where('whatsapp_account_id', $whatsappAccount->id)
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('status', Message::STATUS_PENDING)
                ->where(function ($query): void {
                    $query
                        ->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<=', now());
                })
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

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Message is not claimable.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message claimed.',
            'data' => [
                'message_id' => $result['message']->id,
                'status' => $result['message']->status,
                'attempt_id' => $result['attempt']->id,
                'attempt_number' => $result['attempt']->attempt_number,
            ],
        ]);
    }
}