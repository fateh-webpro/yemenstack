<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        try {
            $siteSettings = SiteSetting::currentOrFallback();

            if (! $siteSettings->is_maintenance_mode) {
                return $next($request);
            }

            return response()->view('maintenance', [
                'siteSettings' => $siteSettings,
            ], 503);
        } catch (Throwable) {
            return $next($request);
        }
    }

    protected function shouldBypass(Request $request): bool
    {
        return $request->is('admin')
            || $request->is('admin/*')
            || $request->is('livewire*')
            || $request->is('storage/*')
            || $request->is('images/*')
            || $request->is('build/*')
            || $request->is('favicon.ico');
    }
}