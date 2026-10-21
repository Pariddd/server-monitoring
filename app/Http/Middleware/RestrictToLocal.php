<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi halaman web (dashboard, halaman Aturan, data polling) hanya untuk alamat
 * yang diizinkan (default: komputer server itu sendiri, 127.0.0.1 / ::1).
 *
 * Alasan: dashboard belum memiliki login, sedangkan server harus menerima koneksi
 * dari jaringan agar ESP32 dapat mengirim data. Endpoint API ingest (/api/ingest/*)
 * tidak memakai middleware ini; endpoint itu dilindungi token per sumber.
 *
 * IP dibaca dari REMOTE_ADDR ($request->ip() tanpa trusted proxy), sehingga header
 * X-Forwarded-For dari klien tidak dapat dipakai untuk melewati pembatasan ini.
 */
class RestrictToLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('monitoring.dashboard.allowed_ips', ['127.0.0.1', '::1']);
        $ip = (string) $request->ip();

        if ($ip === '' || ! IpUtils::checkIp($ip, $allowed)) {
            abort(403, 'Dashboard hanya dapat dibuka dari komputer server.');
        }

        return $next($request);
    }
}
