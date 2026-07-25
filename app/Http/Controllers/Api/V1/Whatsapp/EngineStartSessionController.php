<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\WhatsappSessionStartService;
use Illuminate\Http\JsonResponse;

class EngineStartSessionController extends Controller
{
    public function __invoke(
        WhatsappAccount $whatsappAccount,
        WhatsappSessionStartService $sessionStartService,
    ): JsonResponse {
        if ($message = $sessionStartService->validationError($whatsappAccount)) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        $sessionStartService->requestStart($whatsappAccount);

        return response()->json([
            'success' => true,
            'message' => 'Session start requested.',
            'data' => $sessionStartService->responseData($whatsappAccount),
        ]);
    }
}