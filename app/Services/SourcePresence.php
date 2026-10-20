<?php

namespace App\Services;

use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SourcePresence
{
    public function touch(Source $source, Request $request, CarbonImmutable $now): void
    {
        $source->last_seen_at = $now;

        // $request->ip() = REMOTE_ADDR karena tidak ada trusted proxy. X-Forwarded-For tidak dipercaya.
        $ip = $request->ip();
        if ($ip && ! in_array($ip, ['127.0.0.1', '::1'], true) && $source->ip_address !== $ip) {
            $source->ip_address = $ip;
            $source->ip_updated_at = $now;
        }

        $source->save();
    }
}
