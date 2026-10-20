<?php

namespace App\Http\Middleware;

use App\Models\Source;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSource
{
    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $token = $request->bearerToken();

        if (! $token || strlen($token) > 128) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $source = Source::where('token_hash', Source::hashToken($token))
            ->where('is_active', true)
            ->first();

        if (! $source) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($type !== null && $source->type !== $type) {
            return response()->json(['message' => 'Token ini bukan untuk endpoint ini.'], 403);
        }

        $request->attributes->set('source', $source);

        return $next($request);
    }
}
