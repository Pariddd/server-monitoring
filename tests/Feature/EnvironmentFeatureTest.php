<?php

use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Metric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| Saklar fitur lingkungan ruang server (MONITORING_ENVIRONMENT_ENABLED), diputuskan 22 Okt 2026.
| false = Judul 1 (resource server saja): kode ESP32 tetap ada, tetapi tidak menerima data,
|         tidak dievaluasi, tidak mengirim notifikasi, dan tidak tampil di dashboard/Aturan.
| true  = Judul 2: perilaku lengkap (diuji juga oleh semua tes lain).
*/

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->now = freezeAt(t0()->addSeconds(1));
    seedRules();
    [$this->host, $this->hostToken] = makeSource('h1', 'host');
    [$this->esp, $this->espToken] = makeSource('esp32-01', 'esp32');
});

function postEsp32Reading($test, string $token, CarbonImmutable $at): TestResponse
{
    return $test->postJson('/api/ingest/esp32', ['temperature' => 26.4, 'humidity' => 55.1, 'ts' => $at->getTimestamp()], [
        'Authorization' => 'Bearer ' . $token,
    ]);
}

/** State FIRING ESP32 yang tertinggal (mis. fitur dimatikan saat alarm suhu sedang FIRING). */
function staleEsp32Firing($source, CarbonImmutable $at): AlertState
{
    return AlertState::create([
        'alert_rule_id' => rule('temp_high')->id,
        'source_id' => $source->id,
        'tag' => '',
        'state' => 'FIRING',
        'state_since' => $at->subHour(),
        'breach_at' => $at->subHour(),
        'last_value' => 28.4,
        'last_notified_at' => $at->subHour(),
        'last_sample_at' => $at->subHour(),
    ]);
}

describe('fitur lingkungan NONAKTIF (Judul 1)', function () {
    beforeEach(function () {
        config(['monitoring.features.environment' => false]);
    });

    it('menutup endpoint ESP32 dengan 404 sebelum token diperiksa', function () {
        postEsp32Reading($this, $this->espToken, t0())->assertNotFound();
        postEsp32Reading($this, 'pm_' . str_repeat('0', 64), t0())->assertNotFound(); // bukan 401

        expect(Metric::count())->toBe(0)
            ->and($this->esp->fresh()->last_seen_at)->toBeNull();
    });

    it('tetap menerima data Telegraf dari host', function () {
        $this->postJson('/api/ingest/telegraf', ['metrics' => [
            ['name' => 'win_cpu', 'tags' => ['instance' => '_Total'], 'fields' => ['Percent_Processor_Time' => 12.5], 'timestamp' => t0()->getTimestamp()],
        ]], ['Authorization' => 'Bearer ' . $this->hostToken])->assertOk();

        expect(Metric::where('source_id', $this->host->id)->count())->toBe(1);
    });

    it('tidak mengevaluasi aturan ESP32 walaupun aktif di database, aturan host tetap jalan', function () {
        $last = series($this->esp, 'temperature', [30, 30, 30, 30, 30, 30, 30], t0());
        series($this->esp, 'humidity', [100, 100, 100, 100, 100, 100, 100], t0());
        series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
        $this->esp->update(['last_seen_at' => t0()->subHour()]); // heartbeat ESP32 pasti terlewati

        evaluateAt($last->addSeconds(5));

        expect(rule('temp_high')->is_enabled)->toBeTrue()
            ->and(AlertState::where('source_id', $this->esp->id)->count())->toBe(0)
            ->and(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING'])
            ->and(notificationKinds('cpu_high'))->toBe(['FIRING'])
            ->and(AlertNotification::count())->toBe(1);
    });

    it('tidak mengirim REPEAT untuk state ESP32 yang tertinggal FIRING', function () {
        staleEsp32Firing($this->esp, $this->now);

        evaluateAt($this->now->addMinutes(20)); // jauh melewati cooldown 600 dtk

        expect(AlertNotification::count())->toBe(0);
    });

    it('menyembunyikan ruang server, alert ESP32, dan aturan ESP32 nonaktif dari data ringkasan', function () {
        staleEsp32Firing($this->esp, $this->now);
        rule('humidity_high')->update(['is_enabled' => false]);

        $this->getJson('/data/summary')
            ->assertOk()
            ->assertJsonPath('features.environment', false)
            ->assertJsonPath('room', null)
            ->assertJsonPath('counts.total', 1)
            ->assertJsonCount(0, 'alerts')
            ->assertJsonCount(0, 'disabled_rules');
    });

    it('tidak merender panel Ruang server di dashboard', function () {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('id="ruang-server"', false)
            ->assertDontSee('Lingkungan ruang server')
            ->assertSee('Semua server normal')
            ->assertDontSee('Semua server dan ruang server normal');
    });

    it('menyembunyikan aturan ESP32 dari halaman Aturan', function () {
        $this->get('/rules')
            ->assertOk()
            ->assertSee('Resource host')
            ->assertSee(rule('heartbeat_host')->name)
            ->assertDontSee('Ruang server (ESP32)')
            ->assertDontSee(rule('temp_high')->name)
            ->assertDontSee(rule('humidity_high')->name)
            ->assertDontSee(rule('heartbeat_esp32')->name);
    });

    it('menolak perubahan pada aturan ESP32 yang tersembunyi tanpa menyimpan apa pun', function () {
        $before = AlertRule::orderBy('id')->get(['key', 'threshold', 'for_seconds', 'is_enabled'])->toArray();
        $tempId = rule('temp_high')->id;

        $this->from('/rules')
            ->put('/rules', rulesPayload(['temp_high' => ['is_enabled' => '0'], 'cpu_high' => ['for_seconds' => 30]]))
            ->assertRedirect('/rules')
            ->assertSessionHasErrors("rules.{$tempId}");

        expect(AlertRule::orderBy('id')->get(['key', 'threshold', 'for_seconds', 'is_enabled'])->toArray())->toBe($before);
    });

    it('menyimpan form yang hanya berisi aturan host (seperti yang dikirim browser)', function () {
        $hidden = AlertRule::where('source_type', 'esp32')->pluck('id')->all();
        $payload = rulesPayload(['cpu_high' => ['for_seconds' => 30]]);
        $payload['rules'] = array_diff_key($payload['rules'], array_flip($hidden));

        $this->put('/rules', $payload)->assertRedirect('/rules')->assertSessionHas('status');

        expect(rule('cpu_high')->for_seconds)->toBe(30);
    });

    it('tombol reset hanya mengembalikan aturan host, aturan ESP32 tidak disentuh', function () {
        rule('temp_high')->update(['is_enabled' => false, 'threshold' => 30]);
        rule('mem_high')->update(['is_enabled' => false]);

        $this->post('/rules/reset')->assertRedirect('/rules');

        expect(rule('mem_high')->is_enabled)->toBeTrue()
            ->and(rule('temp_high')->is_enabled)->toBeFalse()
            ->and(rule('temp_high')->threshold)->toBe(30.0);
    });
});

describe('fitur lingkungan AKTIF (Judul 2)', function () {
    beforeEach(function () {
        config(['monitoring.features.environment' => true]);
    });

    it('menerima data ESP32', function () {
        postEsp32Reading($this, $this->espToken, t0())->assertOk()->assertJson(['stored' => 2]);
    });

    it('menampilkan panel Ruang server dan aturan ESP32', function () {
        $this->get('/')->assertOk()->assertSee('id="ruang-server"', false);
        $this->get('/rules')->assertOk()->assertSee('Ruang server (ESP32)')->assertSee(rule('heartbeat_esp32')->name);
        $this->getJson('/data/summary')->assertJsonPath('features.environment', true)->assertJsonPath('room.name', 'esp32-01');
    });
});
