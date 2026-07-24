<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use Illuminate\Http\JsonResponse;

class EngineShowSessionController extends Controller
{
    public function __invoke(WhatsappAccount $whatsappAccount): JsonResponse
    {
        $whatsappAccount->loadMissing('client:id,is_active');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $whatsappAccount->id,
                'client_id' => $whatsappAccount->client_id,
                'name' => $whatsappAccount->name,
                'session_name' => $whatsappAccount->session_name,
                'phone_number' => $whatsappAccount->phone_number,
                'is_active' => $whatsappAccount->is_active,
                'client_is_active' => (bool) $whatsappAccount->client?->is_active,
                'session_desired_state' => $whatsappAccount->session_desired_state,
                'status' => $whatsappAccount->status,
                'start_requested_at' => $whatsappAccount->start_requested_at?->toISOString(),
                'stop_requested_at' => $whatsappAccount->stop_requested_at?->toISOString(),
                'last_seen_at' => $whatsappAccount->last_seen_at?->toISOString(),
                'updated_at' => $whatsappAccount->updated_at?->toISOString(),
            ],
        ]);
    }
}