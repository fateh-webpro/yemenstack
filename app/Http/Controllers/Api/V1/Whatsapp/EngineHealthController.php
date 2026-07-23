<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EngineHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'service' => 'whatsapp-engine',
                'status' => 'ok',
            ],
        ]);
    }
}