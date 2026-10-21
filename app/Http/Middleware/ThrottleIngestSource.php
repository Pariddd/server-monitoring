<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit per sumber data (limiter 'ingest-source', 120/menit).
 *
 * Laravel selalu mengurutkan ThrottleRequests (dan turunannya) sebelum middleware biasa,
 * sehingga `throttle:ingest-source` di route berjalan SEBELUM source.auth dan tidak pernah
 * mengenali sumber (semua sumber dari IP yang sama berbagi kuota). Kelas ini membungkus
 * ThrottleRequests tanpa mewarisinya, sehingga urutan route dipertahankan:
 * throttle:ingest-ip → source.auth → source.throttle.
 */
class ThrottleIngestSource
{
    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->throttle->handle($request, $next, 'ingest-source');
    }
}
