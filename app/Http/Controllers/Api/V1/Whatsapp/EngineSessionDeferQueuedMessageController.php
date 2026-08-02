<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineSessionDeferQueuedMessageController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsappAccount $whatsappAccount,
        Message $message,
        EngineMessageLifecycleService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'error_message' => ['nullable', 'string'],
            'response_payload' => ['nullable', 'array'],
            'mode' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', 'string', 'max:100'],
            'deferred_at' => ['nullable', 'date'],
        ]);

        $result = $service->deferQueuedMessage($whatsappAccount, $message, $validated);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Message is not queued.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message deferred for recovery.',
            'data' => [
                'id' => $result['message']->id,
                'status' => $result['message']->status,
                'manual_send_requested' => (bool) $result['message']->manual_send_requested,
                'attempt_id' => $result['attempt']->id,
                'attempt_status' => $result['attempt']->status,
            ],
        ]);
    }
}