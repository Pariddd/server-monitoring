<?php

use Illuminate\Support\Facades\Queue;

/*
| Tahap 8f — halaman dan endpoint JSON dashboard (uji asap / smoke test):
| memastikan halaman dan data polling dapat dimuat dengan data nyata.
*/

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    seedRules();
    [$this->host] = makeSource('h1', 'host');
});

it('menampilkan halaman Ringkasan dengan kontrol polling', function () {
    $this->get('/')->assertOk()->assertSee('Perbarui')->assertSee('Data terakhir');
});

it('mengembalikan data ringkasan untuk polling', function () {
    $now = freezeAt(t0()->addSeconds(70));
    series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
    sample($this->host, 'mem_used_pct', 50, t0());
    sample($this->host, 'disk_free_pct', 40, t0(), 'C:');
    $this->host->update(['last_seen_at' => t0()->addSeconds(61)]);
    evaluateAt($now);

    $this->getJson('/data/summary')
        ->assertOk()
        ->assertJsonStructure(['generated_at', 'thresholds', 'disabled_rules', 'counts', 'hosts', 'room', 'alerts'])
        ->assertJsonPath('counts.total', 1)
        ->assertJsonPath('counts.FIRING', 1)
        ->assertJsonPath('hosts.0.name', 'h1')
        ->assertJsonCount(1, 'alerts');
});

it('mencantumkan aturan nonaktif di data ringkasan', function () {
    rule('mem_high')->update(['is_enabled' => false]);

    $this->getJson('/data/summary')
        ->assertOk()
        ->assertJsonPath('disabled_rules.0', rule('mem_high')->name);
});

it('menampilkan halaman dan data detail host', function () {
    freezeAt(t0()->addMinute());
    sample($this->host, 'cpu_usage', 30, t0());

    $this->get('/servers/h1')->assertOk();
    $this->getJson('/data/servers/h1')->assertOk();
    $this->getJson('/data/servers/h1?range=tidak-dikenal')->assertOk(); // jatuh ke rentang default
});

it('mengembalikan 404 untuk host yang tidak ada atau sumber ESP32', function () {
    makeSource('esp32-01', 'esp32');

    $this->get('/servers/tidak-ada')->assertNotFound();
    $this->get('/servers/esp32-01')->assertNotFound();
    $this->getJson('/data/servers/esp32-01')->assertNotFound();
});
