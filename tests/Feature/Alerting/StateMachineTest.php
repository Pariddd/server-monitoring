<?php

use App\Jobs\SendTelegramNotification;
use App\Models\AlertNotification;
use App\Models\AlertState;
use Illuminate\Support\Facades\Queue;

/*
| Tahap 8b — mesin status aturan ambang dan heartbeat, diuji lewat AlertEngine
| (jalur yang sama dengan `php artisan alerts:evaluate`).
| Sampel tiap 10 dtk seperti Telegraf. Queue dipalsukan: notifikasi hanya
| diperiksa masuk antrean, tidak dikirim (pengiriman diuji di TelegramJobTest).
*/

beforeEach(function () {
    Queue::fake();
    seedRules();
    [$this->host] = makeSource('h1', 'host');
});

describe('aturan ambang ">" (cpu_high: > 85 selama 60 dtk, pulih < 80)', function () {
    it('tetap NORMAL tanpa event saat nilai tidak melewati ambang', function () {
        $last = series($this->host, 'cpu_usage', [40, 50, 85, 60], t0()); // 85 bukan > 85
        evaluateAt($last->addSeconds(5));

        expect(transitions('cpu_high'))->toBe([])
            ->and(stateOf('cpu_high')->state)->toBe('NORMAL')
            ->and(notificationKinds('cpu_high'))->toBe([]);
        Queue::assertNothingPushed();
    });

    it('PENDING lalu FIRING tepat setelah durasi tahan 60 dtk', function () {
        // 7 sampel: t0, t0+10, ..., t0+60 → tertahan 60 dtk pada sampel ke-7.
        $last = series($this->host, 'cpu_usage', [90, 91, 92, 93, 94, 95, 96], t0());
        evaluateAt($last->addSeconds(5));

        $state = stateOf('cpu_high');
        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING'])
            ->and($state->state)->toBe('FIRING')
            ->and($state->breach_at->format('H:i:s'))->toBe('10:00:05')
            ->and(notificationKinds('cpu_high'))->toBe(['FIRING']);

        $n = AlertNotification::firstOrFail();
        expect($n->t_breach->format('H:i:s'))->toBe('10:00:05') // awal pelanggaran, bukan saat FIRING
            ->and($n->status)->toBe('queued');
        Queue::assertPushed(SendTelegramNotification::class, fn($job) => $job->notificationId === $n->id);
    });

    it('belum FIRING bila pelanggaran baru tertahan 59 dtk', function () {
        series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90], t0()); // t0 .. t0+50
        sample($this->host, 'cpu_usage', 90, t0()->addSeconds(59));
        evaluateAt(t0()->addSeconds(65));

        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING'])
            ->and(stateOf('cpu_high')->state)->toBe('PENDING');
        Queue::assertNothingPushed();
    });

    it('kembali NORMAL tanpa notifikasi bila pelanggaran berhenti sebelum 60 dtk', function () {
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 70], t0());
        evaluateAt($last->addSeconds(5));

        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>NORMAL'])
            ->and(stateOf('cpu_high')->breach_at)->toBeNull()
            ->and(notificationKinds('cpu_high'))->toBe([]);
    });

    it('menerapkan hysteresis: tetap FIRING di antara 80 dan 85, RESOLVED saat < 80', function () {
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0()); // FIRING di t0+60
        $last = series($this->host, 'cpu_usage', [84, 82, 80], $last->addSeconds(10));  // 80 bukan < 80
        evaluateAt($last->addSeconds(5));
        expect(stateOf('cpu_high')->state)->toBe('FIRING');

        $recovery = $last->addSeconds(10);
        sample($this->host, 'cpu_usage', 79.9, $recovery);
        evaluateAt($recovery->addSeconds(5));

        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING', 'FIRING>RESOLVED'])
            ->and(notificationKinds('cpu_high'))->toBe(['FIRING', 'RESOLVED']);

        $resolved = AlertNotification::where('kind', 'RESOLVED')->firstOrFail();
        expect($resolved->t_breach->equalTo($recovery))->toBeTrue(); // waktu sampel pulih
    });

    it('RESOLVED menjadi NORMAL pada sampel berikutnya tanpa event baru', function () {
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90, 70, 60], t0());
        evaluateAt($last->addSeconds(5));

        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING', 'FIRING>RESOLVED'])
            ->and(stateOf('cpu_high')->state)->toBe('NORMAL');
    });

    it('memproses sampel bertahap di beberapa siklus evaluasi (seperti scheduler tiap 10 dtk)', function () {
        foreach (range(0, 6) as $i) {
            $at = t0()->addSeconds($i * 10);
            sample($this->host, 'cpu_usage', 95, $at);
            evaluateAt($at->addSeconds(5));
        }

        expect(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING'])
            ->and(stateOf('cpu_high')->last_sample_at->format('H:i:s'))->toBe('10:01:05')
            ->and(notificationKinds('cpu_high'))->toBe(['FIRING']);
    });

    it('mengabaikan data terlambat (lebih tua dari sampel terakhir yang diproses)', function () {
        sample($this->host, 'cpu_usage', 50, t0()->addSeconds(20));
        evaluateAt(t0()->addSeconds(25));

        sample($this->host, 'cpu_usage', 99, t0()->addSeconds(10)); // datang terlambat
        evaluateAt(t0()->addSeconds(35));

        expect(transitions('cpu_high'))->toBe([])
            ->and(stateOf('cpu_high')->last_value)->toBe(50.0);
    });
});

describe('durasi tahan 0 dtk', function () {
    it('langsung FIRING pada sampel pertama yang melanggar', function () {
        rule('cpu_high')->update(['for_seconds' => 0]);

        sample($this->host, 'cpu_usage', 90, t0());
        evaluateAt(t0()->addSeconds(5));

        expect(rule('cpu_high')->for_seconds)->toBeInt()
            ->and(transitions('cpu_high'))->toBe(['NORMAL>FIRING'])
            ->and(notificationKinds('cpu_high'))->toBe(['FIRING']);
    });
});

describe('aturan ambang "<" per volume (disk_low: < 10% selama 60 dtk, pulih > 12%)', function () {
    it('mengevaluasi tiap volume secara terpisah', function () {
        series($this->host, 'disk_free_pct', [9, 9, 9, 9, 9, 9, 9], t0(), 'C:');
        $last = series($this->host, 'disk_free_pct', [50, 50, 50, 50, 50, 50, 50], t0(), 'D:');
        evaluateAt($last->addSeconds(5));

        expect(transitions('disk_low', 'C:'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING'])
            ->and(transitions('disk_low', 'D:'))->toBe([])
            ->and(stateOf('disk_low', 'D:')->state)->toBe('NORMAL');
    });

    it('baru pulih saat ruang bebas > 12%, bukan saat kembali di atas 10%', function () {
        $last = series($this->host, 'disk_free_pct', [9, 9, 9, 9, 9, 9, 9, 11, 12], t0(), 'C:');
        evaluateAt($last->addSeconds(5));
        expect(stateOf('disk_low', 'C:')->state)->toBe('FIRING');

        sample($this->host, 'disk_free_pct', 12.5, $last->addSeconds(10), 'C:');
        evaluateAt($last->addSeconds(15));
        expect(stateOf('disk_low', 'C:')->state)->toBe('RESOLVED');
    });

    it('tidak FIRING oleh fluktuasi singkat di sekitar 10% (alasan durasi 60 dtk)', function () {
        $last = series($this->host, 'disk_free_pct', [9.5, 9.8, 10.4, 9.9, 10.2, 9.7, 10.1], t0(), 'C:');
        evaluateAt($last->addSeconds(5));

        expect(notificationKinds('disk_low'))->toBe([]);
    });
});

describe('aturan layanan (service_down: state != 4 selama 20 dtk, pulih == 4)', function () {
    it('FIRING setelah layanan berhenti 20 dtk dan RESOLVED saat running lagi', function () {
        $last = series($this->host, 'service_state', [4, 1, 1, 1, 4], t0(), 'Spooler');
        evaluateAt($last->addSeconds(5));

        expect(transitions('service_down', 'Spooler'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING', 'FIRING>RESOLVED'])
            ->and(notificationKinds('service_down'))->toBe(['FIRING', 'RESOLVED']);
    });

    it('menganggap state transisi (mis. 2 = start pending) sebagai tidak running', function () {
        $last = series($this->host, 'service_state', [2, 2, 2], t0(), 'WSearch');
        evaluateAt($last->addSeconds(5));

        expect(stateOf('service_down', 'WSearch')->state)->toBe('FIRING');
    });
});

describe('pesan ulang (REPEAT) setelah cooldown 600 dtk', function () {
    beforeEach(function () {
        $this->firedAt = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0())->addSeconds(5);
        evaluateAt($this->firedAt);
    });

    it('belum mengirim ulang sebelum 600 dtk', function () {
        evaluateAt($this->firedAt->addSeconds(599));

        expect(notificationKinds('cpu_high'))->toBe(['FIRING']);
    });

    it('mengirim REPEAT tanpa event baru setelah 600 dtk selama masih FIRING', function () {
        evaluateAt($this->firedAt->addSeconds(600));

        $repeat = AlertNotification::where('kind', 'REPEAT')->firstOrFail();
        expect(notificationKinds('cpu_high'))->toBe(['FIRING', 'REPEAT'])
            ->and($repeat->alert_event_id)->toBeNull()
            ->and($repeat->message)->toStartWith('[MASIH FIRING]')
            ->and(transitions('cpu_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING']);
    });

    it('menghitung cooldown berikutnya dari pesan terakhir', function () {
        evaluateAt($this->firedAt->addSeconds(600));
        evaluateAt($this->firedAt->addSeconds(1199));
        expect(notificationKinds('cpu_high'))->toBe(['FIRING', 'REPEAT']);

        evaluateAt($this->firedAt->addSeconds(1200));
        expect(notificationKinds('cpu_high'))->toBe(['FIRING', 'REPEAT', 'REPEAT']);
    });
});

describe('heartbeat host (tanpa data > 30 dtk)', function () {
    it('tidak melakukan apa pun bila sumber belum pernah mengirim data', function () {
        evaluateAt(t0());

        expect(transitions('heartbeat_host'))->toBe([]);
    });

    it('tetap NORMAL bila data terakhir tepat 30 dtk lalu (batas belum terlampaui)', function () {
        $this->host->update(['last_seen_at' => t0()]);
        evaluateAt(t0()->addSeconds(30));

        expect(transitions('heartbeat_host'))->toBe([]);
    });

    it('FIRING bila tanpa data lebih dari 30 dtk, lalu RESOLVED saat data masuk lagi', function () {
        $this->host->update(['last_seen_at' => t0()]);
        evaluateAt(t0()->addSeconds(31));

        expect(transitions('heartbeat_host'))->toBe(['NORMAL>FIRING'])
            ->and(stateOf('heartbeat_host')->breach_at->format('H:i:s'))->toBe('10:00:05');

        $this->host->update(['last_seen_at' => t0()->addSeconds(40)]);
        evaluateAt(t0()->addSeconds(45));

        expect(transitions('heartbeat_host'))->toBe(['NORMAL>FIRING', 'FIRING>RESOLVED'])
            ->and(notificationKinds('heartbeat_host'))->toBe(['FIRING', 'RESOLVED']);
    });
});

describe('cakupan evaluasi', function () {
    it('tidak mengevaluasi aturan yang nonaktif', function () {
        rule('cpu_high')->update(['is_enabled' => false]);
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
        evaluateAt($last->addSeconds(5));

        expect(stateOf('cpu_high'))->toBeNull();
    });

    it('tidak mengevaluasi sumber yang nonaktif', function () {
        $this->host->update(['is_active' => false]);
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 90], t0());
        evaluateAt($last->addSeconds(5));

        expect(AlertState::count())->toBe(0);
    });

    it('hanya menerapkan aturan ESP32 ke sumber ESP32', function () {
        [$esp] = makeSource('esp32-01', 'esp32');
        $last = series($esp, 'temperature', [28, 28, 28, 28, 28, 28, 28], t0());
        series($this->host, 'temperature', [28, 28, 28, 28, 28, 28, 28], t0());
        evaluateAt($last->addSeconds(5));

        expect(AlertState::where('alert_rule_id', rule('temp_high')->id)->pluck('source_id')->all())->toBe([$esp->id])
            ->and(transitions('temp_high'))->toBe(['NORMAL>PENDING', 'PENDING>FIRING']);
    });
});

describe('isi pesan Telegram', function () {
    it('memuat nama aturan, sumber, nilai, dan waktu WIB', function () {
        $last = series($this->host, 'cpu_usage', [90, 90, 90, 90, 90, 90, 91.25], t0());
        evaluateAt($last->addSeconds(5));

        expect(AlertNotification::firstOrFail()->message)->toBe(implode("\n", [
            '[FIRING] CPU > 85% selama 60 dtk',
            'Sumber: h1',
            'Nilai: 91,3%',
            'Waktu data melewati ambang: 2026-10-21 17:00:05 WIB',
        ]));
    });

    it('menampilkan nama layanan dan state Windows', function () {
        $last = series($this->host, 'service_state', [1, 1, 1], t0(), 'Spooler');
        evaluateAt($last->addSeconds(5));

        expect(AlertNotification::firstOrFail()->message)
            ->toContain('Sumber: h1 (Spooler)')
            ->toContain('Nilai: Stopped (state=1)');
    });
});
