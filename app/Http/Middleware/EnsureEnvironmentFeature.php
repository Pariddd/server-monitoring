<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup endpoint ingest ESP32 (404) bila fitur lingkungan dimatikan.
 * Dipasang SEBELUM autentikasi token: token ESP32 tidak diperiksa sama sekali
 * dan last_seen_at sumber ESP32 tidak diperbarui.
 */
class EnsureEnvironmentFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::environment(), 404);

        return $next($request);
    }
}
