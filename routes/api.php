<?php

use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineClaimMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineHealthController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineMarkMessageFailedController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineMarkMessageSentController;
use App\Http\Controllers\Api\V1\Whatsapp\EnginePendingMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineQueuedMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineWhatsappAccountStatusController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/whatsapp/engine')->middleware('whatsapp.engine')->group(function (): void {
    Route::get('/health', EngineHealthController::class);
});

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
    Route::get('/whatsapp/engine/messages/queued', EngineQueuedMessageController::class);
    Route::post('/whatsapp/engine/messages/{message}/mark-sent', EngineMarkMessageSentController::class);
    Route::post('/whatsapp/engine/messages/{message}/mark-failed', EngineMarkMessageFailedController::class);
    Route::post('/whatsapp/engine/account/status', EngineWhatsappAccountStatusController::class);
});