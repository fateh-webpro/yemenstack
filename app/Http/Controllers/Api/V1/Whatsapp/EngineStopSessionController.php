<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use Illuminate\Http\JsonResponse;

class EngineStopSessionController extends Controller
{
    public function __invoke(WhatsappAccount $whatsappAccount): JsonResponse
    {
        $whatsappAccount->requestSessionStop();
        $whatsappAccount->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Session stop requested.',
            'data' => [
                'whatsapp_account_id' => $whatsappAccount->id,
                'session_desired_state' => $whatsappAccount->session_desired_state,
                'status' => $whatsappAccount->status,
                'start_requested_at' => $whatsappAccount->start_requested_at?->toISOString(),
                'stop_requested_at' => $whatsappAccount->stop_requested_at?->toISOString(),
            ],
        ]);
    }
}