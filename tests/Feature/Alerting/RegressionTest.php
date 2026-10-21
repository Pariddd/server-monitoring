<?php

use App\Models\AlertNotification;
use App\Models\AlertState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
| Tahap 8c — tes regresi untuk bug yang pernah ditemukan dan diperbaiki
| (nomor bug mengacu ke docs/CATATAN-IMPLEMENTASI.md §10).
| Tes ini harus selalu lolos; bila gagal, bug lama muncul kembali.
*/

beforeEach(function () {
    Queue::fake();
    seedRules();
    [$this->host] = makeSource('h1', 'host');
});

it('bug #2: evaluator dapat dijalankan berulang dengan state yang dibaca ulang dari database', function () {
    sample($this->host, 'cpu_usage', 50, t0());
    evaluateAt(t0()->addSeconds(5));

    // Siklus kedua: state dimuat dari DB (last_sample_at berasal dari kolom DATETIME).
    sample($this->host, 'cpu_usage', 90, t0()->addSeconds(10));
    freezeAt(t0()->addSeconds(15));
    expect(Artisan::call('alerts:evaluate'))->toBe(0)
        ->and(Artisan::output())->toContain('"samples":1');

    freezeAt(t0()->addSeconds(25));
    expect(Artisan::call('alerts:evaluate'))->toBe(0)
        ->and(transitions('cpu_high'))->toBe(['NORMAL>PENDING']);
});

it('bug #6: aturan yang diaktifkan kembali tidak mengevaluasi ulang data selama nonaktif', function () {
    // Kondisi awal: Spooler running, state sudah ada.
    sample($this->host, 'service_state', 4, t0(), 'Spooler');
    evaluateAt(t0()->addSeconds(5));

    // Nonaktifkan lewat halaman Aturan.
    freezeAt(t0()->addSeconds(8));
    $this->put('/rules', rulesPayload(['service_down' => ['is_enabled' => '0']]))->assertRedirect('/rules');

    // Selama nonaktif: Spooler berhenti 40 dtk lalu running lagi (masih di dalam jendela lookback).
    series($this->host, 'service_state', [1, 1, 1, 1, 1], t0()->addSeconds(10), 'Spooler');
    $back = series($this->host, 'service_state', [4, 4], t0()->addSeconds(60), 'Spooler');

    // Aktifkan kembali, lalu evaluasi.
    $enabledAt = freezeAt($back->addSeconds(2));
    $this->put('/rules', rulesPayload(['service_down' => ['is_enabled' => '1']]))->assertRedirect('/rules');
    evaluateAt($enabledAt->addSeconds(8));

    expect(transitions('service_down', 'Spooler'))->toBe([])
        ->and(notificationKinds('service_down'))->toBe([])
        ->and(stateOf('service_down', 'Spooler')->state)->toBe('NORMAL');

    // Kejadian BARU setelah aktivasi tetap terdeteksi.
    $last = series($this->host, 'service_state', [1, 1, 1], $enabledAt->addSeconds(13), 'Spooler');
    evaluateAt($last->addSeconds(5));

    expect(transitions('service_down', 'Spooler'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING'])
        ->and(notificationKinds('service_down'))->toBe(['FIRING']);
});

it('bug #6: tombol reset default juga tidak mengevaluasi ulang data selama nonaktif', function () {
    sample($this->host, 'service_state', 4, t0(), 'Spooler');
    evaluateAt(t0()->addSeconds(5));

    freezeAt(t0()->addSeconds(8));
    $this->put('/rules', rulesPayload(['service_down' => ['is_enabled' => '0']]));

    $last = series($this->host, 'service_state', [1, 1, 1, 1, 4], t0()->addSeconds(10), 'Spooler');

    $resetAt = freezeAt($last->addSeconds(2));
    $this->post('/rules/reset')->assertRedirect('/rules');
    evaluateAt($resetAt->addSeconds(8));

    expect(rule('service_down')->is_enabled)->toBeTrue()
        ->and(transitions('service_down', 'Spooler'))->toBe([]);
});

it('bug #8: menonaktifkan lewat halaman Aturan mengembalikan state FIRING ke NORMAL', function () {
    $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
    evaluateAt($last->addSeconds(5));
    expect(stateOf('cpu_high')->state)->toBe('FIRING');

    $this->put('/rules', rulesPayload(['cpu_high' => ['is_enabled' => '0']]));

    $state = stateOf('cpu_high');
    expect($state->state)->toBe('NORMAL')
        ->and($state->breach_at)->toBeNull();
});

it('bug #10: penanda last_sample_at saat aktivasi dipotong ke detik sehingga sampel berikutnya tetap diproses', function () {
    sample($this->host, 'service_state', 4, t0(), 'Spooler');
    evaluateAt(t0()->addSeconds(5));
    $this->put('/rules', rulesPayload(['service_down' => ['is_enabled' => '0']]));

    // Aktivasi pada xx:xx:44.600. Bila dibulatkan MySQL menjadi :45, sampel :45 akan terlewat.
    freezeAt('2026-10-21 10:00:44.600');
    $this->put('/rules', rulesPayload(['service_down' => ['is_enabled' => '1']]));

    expect(stateOf('service_down', 'Spooler')->last_sample_at->format('H:i:s'))->toBe('10:00:44');

    sample($this->host, 'service_state', 1, t0()->addSeconds(40), 'Spooler'); // 10:00:45
    evaluateAt(t0()->addSeconds(45));

    expect(transitions('service_down', 'Spooler'))->toBe(['NORMAL>PENDING']);
});

it('mengubah ambang aturan yang tetap aktif tidak me-reset state-nya', function () {
    $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
    evaluateAt($last->addSeconds(5));

    $this->put('/rules', rulesPayload(['cpu_high' => ['threshold' => 88, 'recover_threshold' => 80]]));

    expect(rule('cpu_high')->threshold)->toBe(88.0)
        ->and(stateOf('cpu_high')->state)->toBe('FIRING');
});

describe('laporan latensi (alerts:latency)', function () {
    /** Buat satu notifikasi terkirim dengan waktu-waktu yang diketahui. */
    function sentNotification(AlertState $state, string $kind, string $breach, string $detected, string $queued, string $sent): AlertNotification
    {
        return AlertNotification::create([
            'alert_state_id' => $state->id,
            'kind' => $kind,
            'status' => 'sent',
            'attempts' => 1,
            'message' => 'uji',
            't_breach' => $breach,
            't_detected' => $detected,
            't_queued' => $queued,
            't_sent' => $sent,
        ]);
    }

    beforeEach(function () {
        freezeAt(t0()->subMinutes(10));
        $this->state = AlertState::create([
            'alert_rule_id' => rule('cpu_high')->id,
            'source_id' => $this->host->id,
            'tag' => '',
            'state' => 'NORMAL',
            'state_since' => t0()->subMinutes(10),
        ]);
        freezeAt(t0()->addMinutes(5));
    });

    it('menghitung latensi sistem = total − durasi tahan, per ruas B/C/D', function () {
        // breach 10:00:05, tahan 60 dtk → FIRING secara data 10:01:05.
        sentNotification($this->state, 'FIRING', '2026-10-21 10:00:05', '2026-10-21 10:01:11.000', '2026-10-21 10:01:11.005', '2026-10-21 10:01:12.505');
        sample($this->host, 'cpu_usage', 90, t0()); // received_at = breach + 1 dtk → ruas A = 1 dtk

        Artisan::call('alerts:latency');
        $out = Artisan::output();

        expect($out)->toContain('1 notifikasi')
            ->toMatch('/A_ingest\s*\|\s*1\s*\|\s*1,000/')
            ->toMatch('/B_evaluasi\s*\|\s*1\s*\|\s*6,000/')
            ->toMatch('/C_antre\s*\|\s*1\s*\|\s*0,005/')
            ->toMatch('/D_kirim\s*\|\s*1\s*\|\s*1,500/')
            ->toMatch('/sistem\s*\|\s*1\s*\|\s*7,505/')
            ->toMatch('/total\s*\|\s*1\s*\|\s*67,505/');
    });

    it('mengecualikan REPEAT, notifikasi gagal, dan kondisi awal', function () {
        sentNotification($this->state, 'FIRING', '2026-10-21 10:00:05', '2026-10-21 10:01:11', '2026-10-21 10:01:11', '2026-10-21 10:01:12');
        sentNotification($this->state, 'REPEAT', '2026-10-21 10:00:05', '2026-10-21 10:11:11', '2026-10-21 10:11:11', '2026-10-21 10:11:12');
        sentNotification($this->state, 'FIRING', '2026-10-21 09:40:00', '2026-10-21 09:51:00', '2026-10-21 09:51:00', '2026-10-21 09:51:01'); // sebelum state dibuat
        AlertNotification::create([
            'alert_state_id' => $this->state->id, 'kind' => 'RESOLVED', 'status' => 'failed', 'message' => 'uji',
            't_breach' => '2026-10-21 10:02:05', 't_queued' => '2026-10-21 10:02:11',
        ]);

        Artisan::call('alerts:latency');

        expect(Artisan::output())->toContain('1 notifikasi');
    });
});

it('bug #11: last_sample_at termasuk atribut yang dapat diisi massal (fillable)', function () {
    $state = new AlertState(['last_sample_at' => '2026-10-21 10:00:05']);

    expect($state->last_sample_at?->format('Y-m-d H:i:s'))->toBe('2026-10-21 10:00:05')
        ->and($state->getFillable())->not->toContain('datetime');
});
