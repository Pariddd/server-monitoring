<?php

use App\Models\Metric;
use App\Models\Source;

/*
| Tahap 8d — endpoint penerima data: autentikasi token per sumber, pemetaan
| payload Telegraf/ESP32, idempotensi, validasi stempel waktu, rate limit.
*/

beforeEach(function () {
    $this->now = freezeAt(t0()->addSeconds(1)); // 10:00:06 UTC
    [$this->host, $this->hostToken] = makeSource('h1', 'host');
    [$this->esp, $this->espToken] = makeSource('esp32-01', 'esp32');
});

/** Satu batch JSON seperti yang dikirim outputs.http Telegraf (json_timestamp_units = 1s). */
function telegrafBatch(int $ts): array
{
    return ['metrics' => [
        ['name' => 'win_cpu', 'tags' => ['instance' => '_Total', 'host' => 'PARID'], 'fields' => ['Percent_Processor_Time' => 12.5], 'timestamp' => $ts],
        ['name' => 'win_cpu', 'tags' => ['instance' => '0,1', 'host' => 'PARID'], 'fields' => ['Percent_Processor_Time' => 30.0], 'timestamp' => $ts],
        ['name' => 'mem', 'tags' => ['host' => 'PARID'], 'fields' => ['used_percent' => 71.25, 'available' => 123456], 'timestamp' => $ts],
        ['name' => 'win_disk', 'tags' => ['instance' => 'C:', 'objectname' => 'LogicalDisk'], 'fields' => ['Percent_Free_Space' => 14.2], 'timestamp' => $ts],
        ['name' => 'win_disk', 'tags' => ['instance' => 'HarddiskVolume1', 'objectname' => 'LogicalDisk'], 'fields' => ['Percent_Free_Space' => 40.0], 'timestamp' => $ts],
        ['name' => 'win_services', 'tags' => ['service_name' => 'Spooler', 'display_name' => 'Print Spooler'], 'fields' => ['state' => 4, 'startup_mode' => 2], 'timestamp' => $ts],
        ['name' => 'system', 'tags' => [], 'fields' => ['uptime' => 100], 'timestamp' => $ts],
    ]];
}

function postTelegraf($test, array $payload, ?string $token): Illuminate\Testing\TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

    return $test->postJson('/api/ingest/telegraf', $payload, $headers);
}

describe('autentikasi sumber', function () {
    it('menolak permintaan tanpa token (401)', function () {
        postTelegraf($this, telegrafBatch($this->now->getTimestamp()), null)
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    });

    it('menolak token yang salah (401)', function () {
        postTelegraf($this, telegrafBatch($this->now->getTimestamp()), 'pm_' . str_repeat('0', 64))->assertStatus(401);
        expect(Metric::count())->toBe(0);
    });

    it('menolak token yang terlalu panjang tanpa query database (401)', function () {
        postTelegraf($this, telegrafBatch($this->now->getTimestamp()), str_repeat('a', 129))->assertStatus(401);
    });

    it('menolak token milik sumber yang nonaktif (401)', function () {
        $this->host->update(['is_active' => false]);

        postTelegraf($this, telegrafBatch($this->now->getTimestamp()), $this->hostToken)->assertStatus(401);
    });

    it('menolak token ESP32 di endpoint Telegraf dan sebaliknya (403)', function () {
        postTelegraf($this, telegrafBatch($this->now->getTimestamp()), $this->espToken)->assertStatus(403);

        $this->postJson('/api/ingest/esp32', ['temperature' => 25, 'humidity' => 50, 'ts' => $this->now->getTimestamp()], [
            'Authorization' => 'Bearer ' . $this->hostToken,
        ])->assertStatus(403);
    });

    it('menyimpan token hanya sebagai hash SHA-256', function () {
        $stored = Source::whereKey($this->host->id)->value('token_hash');

        expect($stored)->toBe(hash('sha256', $this->hostToken))
            ->and($stored)->not->toContain('pm_');
    });
});

describe('ingest Telegraf', function () {
    it('memetakan measurement Telegraf ke metrik internal', function () {
        $ts = t0()->getTimestamp();

        postTelegraf($this, telegrafBatch($ts), $this->hostToken)
            ->assertOk()
            ->assertExactJson(['received' => 7, 'stored' => 4, 'duplicates' => 0, 'ignored' => 3, 'rejected' => 0]);

        $rows = Metric::orderBy('metric')->get()->map(fn($m) => [$m->metric, $m->tag, $m->value])->all();
        expect($rows)->toBe([
            ['cpu_usage', '', 12.5],          // hanya instance _Total
            ['disk_free_pct', 'C:', 14.2],    // HarddiskVolume1 diabaikan
            ['mem_used_pct', '', 71.25],
            ['service_state', 'Spooler', 4.0],
        ]);

        $metric = Metric::firstOrFail();
        expect($metric->recorded_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 10:00:05') // waktu data dari Telegraf
            ->and($metric->received_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-21 10:00:06.000'); // waktu terima server
    });

    it('idempoten: batch yang dikirim ulang tidak menggandakan data', function () {
        $batch = telegrafBatch(t0()->getTimestamp());

        postTelegraf($this, $batch, $this->hostToken)->assertJson(['stored' => 4, 'duplicates' => 0]);
        postTelegraf($this, $batch, $this->hostToken)->assertJson(['stored' => 0, 'duplicates' => 4]);

        expect(Metric::count())->toBe(4);
    });

    it('memperbarui last_seen_at sumber (dasar aturan heartbeat)', function () {
        postTelegraf($this, telegrafBatch(t0()->getTimestamp()), $this->hostToken)->assertOk();

        expect($this->host->fresh()->last_seen_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-21 10:00:06.000');
    });

    it('menolak stempel waktu > 60 dtk di masa depan dan > 24 jam di masa lalu', function () {
        $now = $this->now->getTimestamp();
        $payload = ['metrics' => [
            ['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 50], 'timestamp' => $now + 61],
            ['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 50], 'timestamp' => $now - 86401],
            ['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 50], 'timestamp' => $now + 60],
        ]];

        postTelegraf($this, $payload, $this->hostToken)->assertJson(['stored' => 1, 'rejected' => 2]);
    });

    it('menolak nilai field yang bukan angka', function () {
        $payload = ['metrics' => [
            ['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 'tinggi'], 'timestamp' => t0()->getTimestamp()],
            ['name' => 'mem', 'tags' => [], 'fields' => ['free' => 1], 'timestamp' => t0()->getTimestamp()],
        ]];

        postTelegraf($this, $payload, $this->hostToken)->assertJson(['stored' => 0, 'rejected' => 2]);
    });

    it('mengembalikan 422 JSON untuk payload yang tidak valid', function () {
        postTelegraf($this, ['metrics' => []], $this->hostToken)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'error']);

        postTelegraf($this, ['metrics' => [['name' => 'mem', 'fields' => ['used_percent' => 1]]]], $this->hostToken)
            ->assertStatus(422); // timestamp wajib
    });

    it('mencatat IP sumber kecuali alamat loopback', function () {
        postTelegraf($this, telegrafBatch(t0()->getTimestamp()), $this->hostToken)->assertOk();
        expect($this->host->fresh()->ip_address)->toBeNull(); // 127.0.0.1 diabaikan

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20']);
        postTelegraf($this, telegrafBatch(t0()->addSeconds(10)->getTimestamp()), $this->hostToken)->assertOk();
        expect($this->host->fresh()->ip_address)->toBe('192.168.1.20');
    });
});

describe('ingest ESP32', function () {
    function postEsp32($test, array $payload, string $token): Illuminate\Testing\TestResponse
    {
        return $test->postJson('/api/ingest/esp32', $payload, ['Authorization' => 'Bearer ' . $token]);
    }

    it('menyimpan suhu dan kelembaban dengan waktu baca sensor (ts)', function () {
        postEsp32($this, ['temperature' => 26.4, 'humidity' => 55.1, 'ts' => t0()->getTimestamp()], $this->espToken)
            ->assertOk()
            ->assertJson(['stored' => 2]);

        $rows = Metric::where('source_id', $this->esp->id)->orderBy('metric')->get();
        expect($rows->pluck('metric')->all())->toBe(['humidity', 'temperature'])
            ->and($rows->pluck('value')->all())->toBe([55.1, 26.4])
            ->and($rows->first()->recorded_at->format('H:i:s'))->toBe('10:00:05')
            ->and($this->esp->fresh()->last_seen_at)->not->toBeNull();
    });

    it('idempoten untuk pembacaan yang dikirim ulang', function () {
        $payload = ['temperature' => 26.4, 'humidity' => 55.1, 'ts' => t0()->getTimestamp()];

        postEsp32($this, $payload, $this->espToken)->assertJson(['stored' => 2]);
        postEsp32($this, $payload, $this->espToken)->assertJson(['stored' => 0]);
    });

    it('menolak nilai di luar rentang sensor DHT22 (422)', function () {
        postEsp32($this, ['temperature' => 26, 'humidity' => 120, 'ts' => t0()->getTimestamp()], $this->espToken)->assertStatus(422);
        postEsp32($this, ['temperature' => 90, 'humidity' => 50, 'ts' => t0()->getTimestamp()], $this->espToken)->assertStatus(422);
        postEsp32($this, ['temperature' => 26, 'humidity' => 50], $this->espToken)->assertStatus(422);
    });

    it('menolak ts yang tidak sinkron (mis. NTP belum jalan → ts = 0)', function () {
        postEsp32($this, ['temperature' => 26, 'humidity' => 50, 'ts' => 0], $this->espToken)
            ->assertStatus(422)
            ->assertJson(['message' => 'Stempel waktu di luar rentang. Periksa sinkronisasi NTP.']);
    });
});

describe('rate limit', function () {
    it('membatasi 120 permintaan per menit per sumber (permintaan ke-121 → 429)', function () {
        $payload = ['metrics' => [['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 50], 'timestamp' => t0()->getTimestamp()]]];

        for ($i = 1; $i <= 120; $i++) {
            postTelegraf($this, $payload, $this->hostToken)->assertOk();
        }
        postTelegraf($this, $payload, $this->hostToken)->assertStatus(429);

        // Sumber lain tidak ikut terblokir.
        $this->postJson('/api/ingest/esp32', ['temperature' => 25, 'humidity' => 50, 'ts' => t0()->getTimestamp()], [
            'Authorization' => 'Bearer ' . $this->espToken,
        ])->assertOk();
    });

    it('membatasi 300 permintaan per menit per IP sebelum token diperiksa', function () {
        $payload = ['metrics' => [['name' => 'mem', 'tags' => [], 'fields' => ['used_percent' => 50], 'timestamp' => t0()->getTimestamp()]]];

        for ($i = 1; $i <= 300; $i++) {
            postTelegraf($this, $payload, 'pm_salah')->assertStatus(401);
        }
        // Permintaan ke-301 dari IP yang sama ditolak walaupun tokennya benar.
        postTelegraf($this, $payload, $this->hostToken)->assertStatus(429);
    });
});
