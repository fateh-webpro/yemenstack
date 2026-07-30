<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineClaimMessageController extends Controller
{
    public function __invoke(Request $request, Message $message, EngineMessageLifecycleService $service): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to claim messages.',
            ], 403);
        }

        if (! $service->messageBelongsToAccount($whatsappAccount, $message)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:manual,automatic'],
        ]);

        $requestedMode = $validated['mode'] ?? null;
        $mode = $service->normalizeClaimMode($requestedMode);
        $result = $service->claimMessage($whatsappAccount, $message, $requestedMode);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => $mode === EngineMessageLifecycleService::CLAIM_MODE_AUTOMATIC
                    ? 'Automatic sending is disabled or the message is not claimable.'
                    : 'Message is not claimable.',
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