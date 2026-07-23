<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiCredential
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (blank($plainToken)) {
            return $this->unauthorized();
        }

        if (! str_starts_with($plainToken, ApiCredential::TOKEN_PREFIX)) {
            return $this->unauthorized();
        }

        $credential = ApiCredential::findByPlainToken($plainToken);

        if (! $credential?->isUsable()) {
            return $this->unauthorized();
        }

        $credential->forceFill([
            'last_used_at' => now(),
        ])->saveQuietly();

        $request->attributes->set('api_credential', $credential);
        $request->attributes->set('client', $credential->client);
        $request->attributes->set('whatsapp_account', $credential->whatsappAccount);

        return $next($request);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid API token.',
        ], 401);
    }
}