<?php

namespace App\Support;

/**
 * Saklar fitur sistem.
 *
 * environment = pemantauan lingkungan ruang server (ESP32 + DHT22).
 *   false → Judul 1 (resource server saja): endpoint /api/ingest/esp32 membalas 404,
 *           aturan bersumber ESP32 tidak dievaluasi dan disembunyikan dari halaman Aturan,
 *           panel "Ruang server" tidak ditampilkan di dashboard.
 *   true  → Judul 2 (resource server + kondisi lingkungan ruang server).
 * Diatur lewat MONITORING_ENVIRONMENT_ENABLED di .env (lihat config/monitoring.php).
 */
final class Features
{
    public static function environment(): bool
    {
        return (bool) config('monitoring.features.environment', false);
    }

    /**
     * Jenis sumber data yang aktif sesuai saklar fitur.
     *
     * @return list<string>
     */
    public static function sourceTypes(): array
    {
        return self::environment() ? ['host', 'esp32'] : ['host'];
    }
}
