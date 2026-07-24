<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineMarkMessageFailedController extends Controller
{
    public function __invoke(Request $request, Message $message, EngineMessageLifecycleService $service): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to mark messages as failed.',
            ], 403);
        }

        if (! $service->messageBelongsToAccount($whatsappAccount, $message)) {
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

        $result = $service->markMessageFailed($whatsappAccount, $message, $validated);

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