<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * IP jaringan utama komputer server (dipakai untuk host yang mengirim data lewat loopback,
 * yaitu Telegraf yang berjalan di komputer server itu sendiri).
 *
 * Cara kerja: membuka socket UDP "terhubung" ke alamat publik lalu membaca alamat lokal yang
 * dipilih sistem operasi untuk rute tersebut (adapter pemegang default route, mis. WiFi).
 * Pada UDP, connect() hanya memilih rute; TIDAK ada paket yang dikirim.
 * Adapter virtual (mis. VirtualBox host-only 192.168.56.1) tidak terpilih karena tidak memegang default route.
 */
class ServerAddress
{
    private const PROBE = 'udp://8.8.8.8:53';

    private const CACHE_KEY = 'monitoring.server_ip';

    private const CACHE_SECONDS = 60;

    /** IP utama server, atau null bila tidak ada rute jaringan (offline). */
    public function primary(): ?string
    {
        $ip = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->detect() ?? '');

        return $ip !== '' ? $ip : null;
    }

    protected function detect(): ?string
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(self::PROBE, $errno, $errstr, 1);
        if ($socket === false) {
            return null;
        }

        $local = stream_socket_get_name($socket, false);
        fclose($socket);

        return is_string($local) ? self::fromSocketName($local) : null;
    }

    /** "192.168.1.36:54321" → "192.168.1.36"; loopback, 0.0.0.0, dan IPv6 → null. */
    public static function fromSocketName(string $name): ?string
    {
        $host = str_contains($name, ':') ? substr($name, 0, strrpos($name, ':')) : $name;

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        if ($host === '0.0.0.0' || str_starts_with($host, '127.')) {
            return null;
        }

        return $host;
    }
}
