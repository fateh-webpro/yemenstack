<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use Illuminate\Http\JsonResponse;

class EngineStartSessionController extends Controller
{
    public function __invoke(WhatsappAccount $whatsappAccount): JsonResponse
    {
        $whatsappAccount->loadMissing('client');

        if (! $whatsappAccount->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp account is inactive.',
            ], 422);
        }

        if ($whatsappAccount->client === null) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp account client is missing.',
            ], 422);
        }

        if (! $whatsappAccount->client->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp account client is inactive.',
            ], 422);
        }

        if (! is_string($whatsappAccount->session_name) || preg_match('/^[a-z0-9_]+$/', $whatsappAccount->session_name) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp session name is invalid.',
            ], 422);
        }

        $whatsappAccount->requestSessionStart();
        $whatsappAccount->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Session start requested.',
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