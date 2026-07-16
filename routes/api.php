<?php

use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineClaimMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EnginePendingMessageController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api.credential')->group(function (): void {
    Route::get('/me', function (Request $request): JsonResponse {
        $credential = $request->attributes->get('api_credential');
        $client = $request->attributes->get('client');
        $whatsappAccount = $request->attributes->get('whatsapp_account');

        return response()->json([
            'success' => true,
            'client' => $client?->name,
            'whatsapp_account' => $whatsappAccount?->name,
            'abilities' => $credential?->abilities ?? [],
            'expires_at' => $credential?->expires_at?->toDateString(),
        ]);
    });

    Route::post('/messages', [MessageController::class, 'store']);
    Route::get('/whatsapp/engine/messages/pending', EnginePendingMessageController::class);
    Route::post('/whatsapp/engine/messages/{message}/claim', EngineClaimMessageController::class);
});