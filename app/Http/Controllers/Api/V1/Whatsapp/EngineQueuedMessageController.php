<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineQueuedMessageController extends Controller
{
    public function __invoke(Request $request, EngineMessageLifecycleService $service): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to process queued messages.',
            ], 403);
        }

        $limit = $service->normalizeLimit((int) $request->integer('limit', 10));
        $messages = $service->listQueuedMessages($whatsappAccount, $limit);

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