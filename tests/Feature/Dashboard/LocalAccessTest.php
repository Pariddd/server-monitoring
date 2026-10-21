<?php

/*
| Pembatasan akses halaman web: server dibuka ke jaringan agar ESP32 dapat mengirim
| data, tetapi dashboard (tanpa login) hanya boleh dibuka dari komputer server.
*/

beforeEach(function () {
    $this->withoutVite();
    seedRules();
    makeSource('h1', 'host');
});

it('mengizinkan dashboard dari komputer server (127.0.0.1)', function () {
    $this->get('/')->assertOk();
    $this->get('/rules')->assertOk();
    $this->getJson('/data/summary')->assertOk();
});

it('mengizinkan dari IPv6 loopback (::1)', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '::1'])->get('/')->assertOk();
});

it('menolak semua halaman web dari perangkat lain di jaringan (403)', function (string $method, string $uri) {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
        ->call($method, $uri)
        ->assertForbidden();
})->with([
    'Ringkasan' => ['GET', '/'],
    'Detail host' => ['GET', '/servers/h1'],
    'Aturan' => ['GET', '/rules'],
    'simpan Aturan' => ['PUT', '/rules'],
    'reset Aturan' => ['POST', '/rules/reset'],
    'data ringkasan' => ['GET', '/data/summary'],
    'data detail' => ['GET', '/data/servers/h1'],
]);

it('tidak mengubah aturan saat permintaan dari jaringan ditolak', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
        ->put('/rules', rulesPayload(['cpu_high' => ['is_enabled' => '0']]))
        ->assertForbidden();

    expect(rule('cpu_high')->is_enabled)->toBeTrue();
});

it('tidak dapat dilewati dengan header X-Forwarded-For palsu', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
        ->withHeader('X-Forwarded-For', '127.0.0.1')
        ->get('/')
        ->assertForbidden();
});

it('mengizinkan alamat atau subnet tambahan dari DASHBOARD_ALLOWED_IPS', function () {
    config(['monitoring.dashboard.allowed_ips' => ['127.0.0.1', '::1', '192.168.1.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])->get('/')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/')->assertForbidden();
});

it('tetap menerima data ESP32 dari jaringan (API tidak dibatasi middleware ini)', function () {
    [, $token] = makeSource('esp32-01', 'esp32');
    freezeAt(t0()->addSecond());

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])
        ->postJson('/api/ingest/esp32', ['temperature' => 25.5, 'humidity' => 55, 'ts' => t0()->getTimestamp()], [
            'Authorization' => 'Bearer ' . $token,
        ])
        ->assertOk();
});
