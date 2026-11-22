<?php

namespace App\Services;

use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SourcePresence
{
    public function __construct(private ServerAddress $server) {}

    public function touch(Source $source, Request $request, CarbonImmutable $now): void
    {
        $source->last_seen_at = $now;

        // $request->ip() = REMOTE_ADDR karena tidak ada trusted proxy. X-Forwarded-For tidak dipercaya.
        $ip = $request->ip();

        // Loopback berarti pengirim adalah komputer server itu sendiri → pakai IP jaringan utama server.
        // Bila server sedang offline (null), IP terakhir yang diketahui dipertahankan.
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            $ip = $this->server->primary();
        }

        if ($ip && $source->ip_address !== $ip) {
            $source->ip_address = $ip;
            $source->ip_updated_at = $now;
        }

        $source->save();
    }
}
