<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineMessageLifecycleService;
use Illuminate\Http\JsonResponse;

class EngineSessionClaimMessageController extends Controller
{
    public function __invoke(
        WhatsappAccount $whatsappAccount,
        Message $message,
        EngineMessageLifecycleService $service,
    ): JsonResponse {
        $whatsappAccount->loadMissing('client');

        if (! $service->messageBelongsToAccount($whatsappAccount, $message)) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        if ($error = $service->processingAccountError($whatsappAccount)) {
            return response()->json([
                'success' => false,
                'message' => $error,
            ], 422);
        }

        $result = $service->claimMessage($whatsappAccount, $message);

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