<?php

namespace Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Token palsu. Dipakai juga untuk menguji penyamaran token (redact). */
    public const FAKE_TELEGRAM_TOKEN = '123456:TEST-TOKEN-JANGAN-DIPAKAI';

    public const FAKE_TELEGRAM_CHAT_ID = '-1000000000';

    /**
     * Berjalan setelah aplikasi dibuat dan SEBELUM trait tes (RefreshDatabase) dijalankan.
     * Mencegah migrate:fresh menghapus database pengembangan bila phpunit.xml salah
     * atau config sedang di-cache (php artisan config:cache).
     */
    protected function setUpTraits(): array
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Tes dibatalkan: koneksi '{$connection}' ke database '{$database}' bukan database tes. "
                . 'Periksa phpunit.xml (DB_DATABASE harus berakhiran _test) lalu jalankan `php artisan config:clear`.'
            );
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Token dan chat asli dari .env tidak pernah dipakai saat tes.
        config([
            'services.telegram.bot_token' => self::FAKE_TELEGRAM_TOKEN,
            'services.telegram.chat_id' => self::FAKE_TELEGRAM_CHAT_ID,
        ]);

        // Tes ditulis untuk sistem lengkap (host + ESP32), tidak bergantung pada isi .env.
        // Perilaku saat fitur lingkungan dimatikan diuji di tests/Feature/EnvironmentFeatureTest.php.
        config(['monitoring.features.environment' => true]);

        // Setiap request HTTP yang tidak dipalsukan dengan Http::fake() membuat tes gagal.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
