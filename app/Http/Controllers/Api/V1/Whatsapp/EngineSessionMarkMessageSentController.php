<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineSessionMarkMessageSentController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsappAccount $whatsappAccount,
        Message $message,
        EngineMessageLifecycleService $service,
    ): JsonResponse {
        if (! $service->messageBelongsToAccount($whatsappAccount, $message)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $validated = $request->validate([
            'external_message_id' => ['nullable', 'string', 'max:255'],
            'response_payload' => ['nullable', 'array'],
            'sent_at' => ['nullable', 'date'],
            'mode' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $service->markMessageSent($whatsappAccount, $message, $validated);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Message is not sendable.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message marked as sent.',
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