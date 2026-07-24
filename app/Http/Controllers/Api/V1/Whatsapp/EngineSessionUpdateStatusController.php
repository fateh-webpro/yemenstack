<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineWhatsappAccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineSessionUpdateStatusController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsappAccount $whatsappAccount,
        EngineWhatsappAccountStatusService $service,
    ): JsonResponse {
        $validated = $request->validate($service->centralValidationRules());
        $data = $service->update($whatsappAccount, $validated);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp account status updated.',
            'data' => $data,
        ]);
    }
}