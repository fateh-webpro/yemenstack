<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineSessionPendingMessagesController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsappAccount $whatsappAccount,
        EngineMessageLifecycleService $service,
    ): JsonResponse {
        $whatsappAccount->loadMissing('client');

        if ($error = $service->processingAccountError($whatsappAccount)) {
            return response()->json([
                'success' => false,
                'message' => $error,
            ], 422);
        }

        $limit = $service->normalizeLimit((int) $request->integer('limit', 10));
        $messages = $service->listPendingMessages($whatsappAccount, $limit);

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
                'scheduled_at' => $message->scheduled_at?->toISOString(),
                'created_at' => $message->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'count' => $messages->count(),
                'limit' => $limit,
            ],
        ]);
    }
}