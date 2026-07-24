<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineListSessionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'desired_state' => ['nullable', 'string', 'in:' . implode(',', [
                WhatsappAccount::SESSION_DESIRED_RUNNING,
                WhatsappAccount::SESSION_DESIRED_STOPPED,
            ])],
        ]);

        $sessions = WhatsappAccount::query()
            ->with('client:id,is_active')
            ->whereHas('client')
            ->when(
                isset($validated['desired_state']),
                fn ($query) => $query->where('session_desired_state', $validated['desired_state'])
            )
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn (WhatsappAccount $account): array => [
                'id' => $account->id,
                'client_id' => $account->client_id,
                'name' => $account->name,
                'session_name' => $account->session_name,
                'phone_number' => $account->phone_number,
                'is_active' => $account->is_active,
                'client_is_active' => (bool) $account->client?->is_active,
                'session_desired_state' => $account->session_desired_state,
                'status' => $account->status,
                'start_requested_at' => $account->start_requested_at?->toISOString(),
                'stop_requested_at' => $account->stop_requested_at?->toISOString(),
                'last_seen_at' => $account->last_seen_at?->toISOString(),
                'updated_at' => $account->updated_at?->toISOString(),
            ])->values(),
        ]);
    }
}