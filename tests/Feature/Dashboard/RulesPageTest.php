<?php

use App\Models\AlertRule;

/*
| Tahap 8f — halaman Aturan: validasi isian dan penyimpanan.
| Kasus 90/95 dan durasi 5 dtk adalah uji black-box Tahap 7e yang diotomatiskan.
*/

beforeEach(function () {
    $this->withoutVite();
    seedRules();
});

/** Snapshot kolom aturan untuk memastikan tidak ada yang tersimpan saat validasi gagal. */
function rulesSnapshot(): array
{
    return AlertRule::orderBy('id')->get(['key', 'threshold', 'recover_threshold', 'for_seconds', 'cooldown_seconds', 'is_enabled'])->toArray();
}

it('menampilkan halaman Aturan tanpa kontrol polling', function () {
    $this->get('/rules')
        ->assertOk()
        ->assertSee('Aturan alert')
        ->assertSee('CPU &gt; 85% selama 60 dtk', false)
        ->assertDontSee('Perbarui')
        ->assertDontSee('Data terakhir');
});

it('menyimpan perubahan valid dan membuat ulang nama aturan', function () {
    $this->put('/rules', rulesPayload(['cpu_high' => ['threshold' => 88, 'recover_threshold' => 82, 'for_seconds' => 30]]))
        ->assertRedirect('/rules')
        ->assertSessionHas('status');

    $cpu = rule('cpu_high');
    expect($cpu->threshold)->toBe(88.0)
        ->and($cpu->recover_threshold)->toBe(82.0)
        ->and($cpu->for_seconds)->toBe(30)
        ->and($cpu->name)->toBe('CPU > 88% selama 30 dtk');
});

it('menolak dan tidak menyimpan apa pun bila ada isian tidak valid', function (array $changes, string $errorField) {
    $before = rulesSnapshot();
    $id = rule(array_key_first($changes))->id;

    $this->from('/rules')
        ->put('/rules', rulesPayload($changes))
        ->assertRedirect('/rules')
        ->assertSessionHasErrors("rules.{$id}.{$errorField}");

    expect(rulesSnapshot())->toBe($before);
})->with([
    'pulih ≥ ambang untuk aturan ">" (uji 7e: memori 90/95)' => [['mem_high' => ['threshold' => 90, 'recover_threshold' => 95]], 'recover_threshold'],
    'pulih = ambang untuk aturan ">"' => [['cpu_high' => ['threshold' => 85, 'recover_threshold' => 85]], 'recover_threshold'],
    'pulih ≤ ambang untuk aturan "<" (disk 10/9)' => [['disk_low' => ['threshold' => 10, 'recover_threshold' => 9]], 'recover_threshold'],
    'durasi 5 dtk (uji 7e)' => [['cpu_high' => ['for_seconds' => 5]], 'for_seconds'],
    'durasi negatif' => [['cpu_high' => ['for_seconds' => -1]], 'for_seconds'],
    'durasi > 3600 dtk' => [['cpu_high' => ['for_seconds' => 3601]], 'for_seconds'],
    'ambang CPU > 100%' => [['cpu_high' => ['threshold' => 101, 'recover_threshold' => 80]], 'threshold'],
    'suhu di luar rentang DHT22' => [['temp_high' => ['threshold' => 85, 'recover_threshold' => 26]], 'threshold'],
    'ambang bukan angka' => [['humidity_high' => ['threshold' => 'enam puluh']], 'threshold'],
    'heartbeat host < 20 dtk' => [['heartbeat_host' => ['for_seconds' => 19]], 'for_seconds'],
    'heartbeat ESP32 < 60 dtk' => [['heartbeat_esp32' => ['for_seconds' => 59]], 'for_seconds'],
    'cooldown < 60 dtk' => [['cpu_high' => ['cooldown_seconds' => 59]], 'cooldown_seconds'],
    'cooldown > 1 hari' => [['cpu_high' => ['cooldown_seconds' => 86401]], 'cooldown_seconds'],
]);

it('menerima batas bawah yang sah', function (array $changes) {
    $this->put('/rules', rulesPayload($changes))->assertSessionHasNoErrors();
})->with([
    'durasi 0 (langsung FIRING)' => [['cpu_high' => ['for_seconds' => 0]]],
    'durasi 10 dtk' => [['cpu_high' => ['for_seconds' => 10]]],
    'heartbeat host 20 dtk' => [['heartbeat_host' => ['for_seconds' => 20]]],
    'heartbeat ESP32 60 dtk' => [['heartbeat_esp32' => ['for_seconds' => 60]]],
    'cooldown 60 dtk' => [['cpu_high' => ['cooldown_seconds' => 60]]],
]);

it('mengabaikan perubahan ambang pada aturan layanan (tidak dapat diubah)', function () {
    $this->put('/rules', rulesPayload(['service_down' => ['threshold' => 1, 'recover_threshold' => 1, 'for_seconds' => 30]]))
        ->assertSessionHasNoErrors();

    $svc = rule('service_down');
    expect($svc->threshold)->toBe(4.0)
        ->and($svc->recover_threshold)->toBe(4.0)
        ->and($svc->for_seconds)->toBe(30);
});

it('menolak id aturan yang tidak dikenal', function () {
    $payload = rulesPayload();
    $payload['rules'][9999] = ['for_seconds' => '60', 'cooldown_seconds' => '600', 'is_enabled' => '1'];

    $this->put('/rules', $payload)->assertSessionHasErrors('rules.9999');
});

it('menyimpan status nonaktif dari sakelar Aktif/Nonaktif', function () {
    $this->put('/rules', rulesPayload(['mem_high' => ['is_enabled' => '0'], 'disk_low' => ['is_enabled' => '0']]));

    expect(rule('mem_high')->is_enabled)->toBeFalse()
        ->and(rule('disk_low')->is_enabled)->toBeFalse()
        ->and(rule('cpu_high')->is_enabled)->toBeTrue();
});

it('mengembalikan semua aturan ke parameter skripsi dengan tombol reset', function () {
    rule('cpu_high')->update(['threshold' => 70, 'is_enabled' => false]);

    $this->post('/rules/reset')->assertRedirect('/rules');

    expect(rule('cpu_high')->threshold)->toBe(85.0)
        ->and(rule('cpu_high')->is_enabled)->toBeTrue();
});
