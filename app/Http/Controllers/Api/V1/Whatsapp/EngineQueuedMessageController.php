<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineQueuedMessageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to process queued messages.',
            ], 403);
        }

        $limit = max(1, min((int) $request->integer('limit', 10), 50));

        $messages = Message::query()
            ->where('client_id', $client->id)
            ->where('whatsapp_account_id', $whatsappAccount->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('status', Message::STATUS_QUEUED)
            ->orderBy('id')
            ->limit($limit)
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

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'recipient' => $message->recipient,
                'sender' => $message->sender,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'payload' => $message->payload,
                'status' => $message->status,
                'created_at' => $message->created_at?->toISOString(),
                'updated_at' => $message->updated_at?->toISOString(),
            ])->values(),
            'meta' => [
                'count' => $messages->count(),
                'limit' => $limit,
            ],
        ]);
    }
}