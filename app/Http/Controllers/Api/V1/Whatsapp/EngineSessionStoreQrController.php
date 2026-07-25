<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EngineWhatsappAccountStatusService;
use App\Services\Whatsapp\WhatsappPairingQrCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineSessionStoreQrController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsappAccount $whatsappAccount,
        WhatsappPairingQrCache $qrCache,
        EngineWhatsappAccountStatusService $statusService,
    ): JsonResponse {
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

        $validated = $request->validate([
            'qr' => ['required', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $expiresAt = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
        $storedExpiresAt = $qrCache->put($whatsappAccount, (string) $validated['qr'], $expiresAt);

        $data = $statusService->update($whatsappAccount, [
            'status' => WhatsappAccount::STATUS_QR_REQUIRED,
            'qr_expires_at' => $storedExpiresAt->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp pairing QR stored.',
            'data' => $data,
        ]);
    }
}