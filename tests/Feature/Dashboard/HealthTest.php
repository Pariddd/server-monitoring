<?php

use App\Models\AlertNotification;
use App\Models\AlertState;
use App\Services\Dashboard\HealthBuilder;
use Illuminate\Support\Facades\Queue;

/*
| Panel "Kesehatan sistem" (pemantauan diri, 24 Okt 2026): evaluator, antrean notifikasi,
| notifikasi gagal 24 jam. Batas evaluator dan antrean 60 dtk (keputusan pengguna).
*/

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    seedRules();
    [$this->host] = makeSource('h1', 'host');
    $this->state = AlertState::create([
        'alert_rule_id' => rule('cpu_high')->id,
        'source_id' => $this->host->id,
        'tag' => '',
        'state' => 'FIRING',
        'state_since' => t0(),
    ]);
});

function healthAt(string|Carbon\CarbonImmutable $at): array
{
    return app(HealthBuilder::class)->build(freezeAt($at));
}

function queuedNotification(AlertState $state, Carbon\CarbonImmutable $queuedAt, string $status = 'queued', ?string $error = null): AlertNotification
{
    return AlertNotification::create([
        'alert_state_id' => $state->id,
        'kind' => 'FIRING',
        'status' => $status,
        'message' => 'uji',
        't_queued' => $queuedAt,
        'last_error' => $error,
    ]);
}

it('menandai evaluator bermasalah bila belum pernah berjalan', function () {
    $h = healthAt(t0());

    expect($h['evaluator']['ok'])->toBeFalse()
        ->and($h['evaluator']['age_seconds'])->toBeNull()
        ->and($h['ok'])->toBeFalse();
});

it('evaluator sehat sampai 60 dtk sejak evaluasi terakhir, lalu bermasalah', function () {
    evaluateAt(t0());

    expect(healthAt(t0()->addSeconds(60))['evaluator'])->toMatchArray(['ok' => true, 'age_seconds' => 60.0, 'limit' => 60])
        ->and(healthAt(t0()->addSeconds(61))['evaluator']['ok'])->toBeFalse();
});

it('antrean sehat bila kosong atau notifikasi tertua < 60 dtk', function () {
    evaluateAt(t0());
    expect(healthAt(t0())['queue'])->toMatchArray(['pending' => 0, 'ok' => true]);

    queuedNotification($this->state, t0());
    expect(healthAt(t0()->addSeconds(59))['queue'])->toMatchArray(['pending' => 1, 'ok' => true])
        ->and(healthAt(t0()->addSeconds(60))['queue']['ok'])->toBeFalse(); // worker mati / tertahan
});

it('menghitung notifikasi gagal 24 jam terakhir dengan token disamarkan', function () {
    queuedNotification($this->state, t0()->subHours(25), 'failed', 'lama');
    queuedNotification($this->state, t0()->subMinutes(10), 'failed', 'HTTP 401: Unauthorized bot' . Tests\TestCase::FAKE_TELEGRAM_TOKEN);

    $failed = healthAt(t0())['failed'];

    expect($failed['count'])->toBe(1)
        ->and($failed['ok'])->toBeFalse()
        ->and($failed['last_error'])->toBe('HTTP 401: Unauthorized bot***')
        ->and($failed['last_error'])->not->toContain(Tests\TestCase::FAKE_TELEGRAM_TOKEN);
});

it('sehat secara keseluruhan bila ketiga indikator sehat', function () {
    evaluateAt(t0());

    expect(healthAt(t0()->addSeconds(5))['ok'])->toBeTrue();
});

it('menyertakan kesehatan di data ringkasan dan menampilkan panelnya', function () {
    evaluateAt(t0());
    freezeAt(t0()->addSeconds(3));

    $this->getJson('/data/summary')
        ->assertOk()
        ->assertJsonPath('health.ok', true)
        ->assertJsonPath('health.evaluator.age_seconds', 3)
        ->assertJsonPath('health.queue.pending', 0);

    $this->get('/')->assertOk()->assertSee('Kesehatan sistem')->assertSee('periksa queue:work');
});
