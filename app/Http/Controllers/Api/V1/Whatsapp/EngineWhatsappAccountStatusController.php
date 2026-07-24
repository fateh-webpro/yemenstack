<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineWhatsappAccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineWhatsappAccountStatusController extends Controller
{
    public function __invoke(Request $request, EngineWhatsappAccountStatusService $service): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        if (! $credential || ! $credential->hasAbility('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'This API token is not allowed to update WhatsApp account status.',
            ], 403);
        }

        if (! $whatsappAccount instanceof WhatsappAccount) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp account not found.',
            ], 404);
        }

        $validated = $request->validate($service->legacyValidationRules());
        $data = $service->update($whatsappAccount, $validated);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp account status updated.',
            'data' => $data,
        ]);
    }
}