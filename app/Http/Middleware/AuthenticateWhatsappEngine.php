<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWhatsappEngine
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('services.whatsapp_engine.internal_token', '');
        $providedToken = $request->bearerToken();

        if ($configuredToken === '' || blank($providedToken)) {
            return $this->unauthorized();
        }

        if (! hash_equals($configuredToken, $providedToken)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }
}