<?php

use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineClaimMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineHealthController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineListSessionsController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineMarkMessageFailedController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineMarkMessageSentController;
use App\Http\Controllers\Api\V1\Whatsapp\EnginePendingMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineQueuedMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionClaimMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionMarkMessageFailedController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionMarkMessageSentController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionPendingMessagesController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionQueuedMessagesController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineSessionUpdateStatusController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineShowSessionController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineStartSessionController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineStopSessionController;
use App\Http\Controllers\Api\V1\Whatsapp\EngineWhatsappAccountStatusController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/whatsapp/engine')->middleware('whatsapp.engine')->group(function (): void {
    Route::get('/health', EngineHealthController::class);
    Route::get('/sessions', EngineListSessionsController::class);
    Route::get('/sessions/{whatsappAccount}', EngineShowSessionController::class);
    Route::post('/sessions/{whatsappAccount}/start', EngineStartSessionController::class);
    Route::post('/sessions/{whatsappAccount}/stop', EngineStopSessionController::class);
    Route::post('/sessions/{whatsappAccount}/status', EngineSessionUpdateStatusController::class);
    Route::get('/sessions/{whatsappAccount}/messages/pending', EngineSessionPendingMessagesController::class);
    Route::post('/sessions/{whatsappAccount}/messages/{message}/claim', EngineSessionClaimMessageController::class);
    Route::get('/sessions/{whatsappAccount}/messages/queued', EngineSessionQueuedMessagesController::class);
    Route::post('/sessions/{whatsappAccount}/messages/{message}/mark-sent', EngineSessionMarkMessageSentController::class);
    Route::post('/sessions/{whatsappAccount}/messages/{message}/mark-failed', EngineSessionMarkMessageFailedController::class);
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