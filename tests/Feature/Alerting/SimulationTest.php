<?php

use App\Models\AlertNotification;
use App\Models\AlertState;
use App\Services\Alerting\Simulation\Scenarios;
use App\Services\Alerting\Simulation\Simulator;
use Illuminate\Support\Facades\Artisan;

/*
| K3 — simulasi aturan naif vs mesin alarm sistem (alerts:simulate), 24 Okt 2026.
| Skenario sintetis memberi kebenaran dasar yang diketahui; aturan cpu_high (> 85, pulih < 80, tahan 60, cooldown 600).
*/

beforeEach(function () {
    seedRules();
});

function simulate(string $scenario): array
{
    $rule = rule('cpu_high');

    return app(Simulator::class)->run($rule, Scenarios::make($scenario, $rule, t0()->getTimestamp()));
}

it('lonjakan singkat: aturan naif mengirim pesan, mesin status tidak', function () {
    $run = simulate('lonjakan');
    $r = $run['results'];

    expect(collect($run['episodes'])->where('sustained', false)->count())->toBe(6)
        ->and(collect($run['episodes'])->where('sustained', true)->count())->toBe(0)
        ->and($r['N1']['total'])->toBe(18)            // 6 lonjakan × 3 sampel
        ->and($r['N2']['alert'])->toBe(6)
        ->and($r['N2']['transient_alerts'])->toBe(6)
        ->and($r['S1']['total'])->toBe(0)
        ->and($r['S2']['total'])->toBe(0)
        ->and($r['S3']['total'])->toBe(0);
});

it('kedip di sekitar ambang: persilangan naif membanjir, mesin status diam', function () {
    $r = simulate('kedip')['results'];

    expect($r['N1']['total'])->toBe(30)
        ->and($r['N2']['alert'])->toBe(30)
        ->and($r['N2']['resolved'])->toBe(30)
        ->and($r['S3']['total'])->toBe(0);
});

it('osilasi: histeresis mencegah FIRING/RESOLVED berulang (S1 vs S2)', function () {
    $run = simulate('osilasi');
    $r = $run['results'];

    expect(collect($run['episodes'])->where('sustained', true)->count())->toBe(6)
        ->and($r['S1']['alert'])->toBe(6)       // tanpa histeresis: pulih tiap turun ke 82,5 lalu FIRING lagi
        ->and($r['S1']['resolved'])->toBe(6)
        ->and($r['S2']['alert'])->toBe(1)       // dengan histeresis: satu alarm sepanjang masalah
        ->and($r['S2']['resolved'])->toBe(1)
        ->and($r['S2']['sustained_detected'])->toBe(6)
        ->and($r['N2']['alert'])->toBe(6);
});

it('kejadian berkelanjutan: semua terdeteksi dengan tunda = durasi tahan, REPEAT tiap 10 menit', function () {
    $r = simulate('berkelanjutan')['results'];

    expect($r['S3']['sustained_total'])->toBe(3)
        ->and($r['S3']['sustained_detected'])->toBe(3)
        ->and($r['S3']['alert'])->toBe(3)
        ->and($r['S3']['resolved'])->toBe(3)
        ->and($r['S3']['repeat'])->toBe(2)          // kejadian 25 mnt: pengingat pada menit ke-10 dan ke-20 setelah FIRING
        ->and($r['S3']['mean_delay'])->toEqual(60)
        ->and($r['S2']['repeat'])->toBe(0)
        ->and($r['N2']['mean_delay'])->toEqual(0)
        ->and($r['N2']['sustained_detected'])->toBe(3);
});

it('perintah alerts:simulate menampilkan tabel dan pengurangan vs aturan naif', function () {
    Artisan::call('alerts:simulate', ['--scenario' => 'campuran']);
    $out = Artisan::output();

    expect($out)->toContain('Aturan cpu_high (> 85, pulih < 80, tahan 60 dtk, cooldown 600 dtk)')
        ->toContain('9 berkelanjutan')
        ->toContain('Sistem: + histeresis + pengingat (cooldown)')
        ->toMatch('/S3\s*\|.*\|\s*9\/9\s*\|\s*0\s*\|/');  // semua berkelanjutan terdeteksi, 0 pemicu dari transien
});

it('memutar ulang data nyata dari tabel metrics tanpa menulis apa pun', function () {
    [$host] = makeSource('h1', 'host');
    series($host, 'cpu_usage', [40, 95, 95, 40, 90, 90, 90, 90, 90, 90, 90, 40], t0());
    $before = [AlertState::count(), AlertNotification::count()];

    Artisan::call('alerts:simulate', ['--rule' => 'cpu_high', '--source' => 'h1']);
    $out = Artisan::output();

    expect($out)->toContain('nyata — 1 deret, 12 sampel')
        ->toContain('1 berkelanjutan')
        ->toContain('1 transien')
        ->and([AlertState::count(), AlertNotification::count()])->toBe($before);
});

it('menolak aturan heartbeat dan skenario tak dikenal', function () {
    expect(Artisan::call('alerts:simulate', ['--rule' => 'heartbeat_host', '--scenario' => 'lonjakan']))->toBe(1)
        ->and(Artisan::call('alerts:simulate', ['--scenario' => 'tidak-ada']))->toBe(1);
});
