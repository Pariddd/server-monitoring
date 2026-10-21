<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
| Tahap 8a — memastikan lingkungan tes aman dan setara dengan sistem asli.
*/

it('memakai database tes MySQL yang terpisah', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_test');
});

it('menjalankan semua migrasi sistem', function () {
    foreach (['sources', 'metrics', 'alert_rules', 'alert_states', 'alert_events', 'alert_notifications'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("tabel {$table} tidak ada");
    }
});

it('memakai UTC di aplikasi dan di sesi MySQL', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(DB::selectOne('select @@session.time_zone as tz')->tz)->toBe('+00:00');
});

it('tidak memakai token Telegram asli dari .env', function () {
    expect(config('services.telegram.bot_token'))->toBe(TestCase::FAKE_TELEGRAM_TOKEN)
        ->and(config('services.telegram.chat_id'))->toBe(TestCase::FAKE_TELEGRAM_CHAT_ID);
});

it('memblokir request HTTP yang tidak dipalsukan', function () {
    Http::get('https://api.telegram.org/');
})->throws(RuntimeException::class);

it('membuat 8 aturan dari config/monitoring.php', function () {
    $rules = seedRules();

    expect($rules->keys()->all())->toBe([
        'cpu_high', 'mem_high', 'disk_low', 'service_down',
        'temp_high', 'humidity_high', 'heartbeat_host', 'heartbeat_esp32',
    ])
        ->and($rules['disk_low']->for_seconds)->toBe(60)
        ->and($rules['cpu_high']->name)->toBe('CPU > 85% selama 60 dtk');
});
