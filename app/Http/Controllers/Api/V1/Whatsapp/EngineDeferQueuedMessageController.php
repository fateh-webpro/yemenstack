<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineDeferQueuedMessageController extends Controller
{
    public function __invoke(Request $request, Message $message, EngineMessageLifecycleService $service): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to process queued messages.',
            ], 403);
        }

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