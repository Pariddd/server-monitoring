<?php

use App\Jobs\SendTelegramNotification;
use App\Models\AlertNotification;
use App\Models\AlertState;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
| Tahap 8e — pengiriman notifikasi Telegram (job antrean). Telegram API
| dipalsukan dengan Http::fake(); tidak ada pesan yang benar-benar terkirim.
*/

beforeEach(function () {
    freezeAt(t0()->addMinute());
    seedRules();
    [$host] = makeSource('h1', 'host');
    $state = AlertState::create([
        'alert_rule_id' => rule('cpu_high')->id,
        'source_id' => $host->id,
        'tag' => '',
        'state' => 'FIRING',
        'state_since' => t0(),
    ]);
    $this->notification = AlertNotification::create([
        'alert_state_id' => $state->id,
        'kind' => 'FIRING',
        'status' => 'queued',
        'message' => "[FIRING] CPU > 85% selama 60 dtk\nSumber: h1",
        't_breach' => t0(),
        't_detected' => t0()->addSeconds(66),
        't_queued' => t0()->addSeconds(66),
    ]);
});

const TG_URL = 'api.telegram.org/*';

function runJob(AlertNotification $n): SendTelegramNotification
{
    $job = (new SendTelegramNotification($n->id))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    return $job;
}

it('menandai terkirim dan menyimpan message_id saat Telegram menerima pesan', function () {
    Http::fake([TG_URL => Http::response(['ok' => true, 'result' => ['message_id' => 4242]], 200)]);

    runJob($this->notification)->assertNotReleased()->assertNotFailed();

    $n = $this->notification->fresh();
    expect($n->status)->toBe('sent')
        ->and($n->telegram_message_id)->toBe(4242)
        ->and($n->attempts)->toBe(1)
        ->and($n->t_sent->format('Y-m-d H:i:s'))->toBe('2026-10-21 10:01:05')
        ->and($n->last_error)->toBeNull();

    Http::assertSent(fn(Request $r) => str_ends_with($r->url(), '/sendMessage')
        && $r['chat_id'] === TestCase::FAKE_TELEGRAM_CHAT_ID
        && $r['text'] === "[FIRING] CPU > 85% selama 60 dtk\nSumber: h1");
});

it('mematuhi retry_after saat Telegram membalas 429 (rate limit)', function () {
    Http::fake([TG_URL => Http::response([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7],
    ], 429)]);

    runJob($this->notification)->assertReleased(delay: 7)->assertNotFailed();

    $n = $this->notification->fresh();
    expect($n->status)->toBe('queued')
        ->and($n->last_error)->toContain('HTTP 429');
});

it('gagal permanen tanpa diulang saat Telegram menolak dengan 4xx (token/chat salah)', function () {
    Http::fake([TG_URL => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);

    runJob($this->notification)->assertFailed()->assertNotReleased();

    expect($this->notification->fresh()->last_error)->toBe('HTTP 400: Bad Request: chat not found');
});

it('melempar exception saat Telegram 5xx agar diulang dengan backoff', function () {
    Http::fake([TG_URL => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)]);

    runJob($this->notification);
})->throws(RuntimeException::class, 'HTTP 502');

it('menyamarkan token bot pada error jaringan', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: timed out for https://api.telegram.org/bot' . TestCase::FAKE_TELEGRAM_TOKEN . '/sendMessage');
    });

    try {
        runJob($this->notification);
        $this->fail('Seharusnya melempar exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain(TestCase::FAKE_TELEGRAM_TOKEN)->toContain('bot***');
    }

    expect($this->notification->fresh()->last_error)
        ->not->toContain(TestCase::FAKE_TELEGRAM_TOKEN)
        ->toContain('HTTP 0');
});

it('menandai failed dan menyamarkan token saat semua percobaan habis', function () {
    (new SendTelegramNotification($this->notification->id))
        ->failed(new RuntimeException('gagal ke https://api.telegram.org/bot' . TestCase::FAKE_TELEGRAM_TOKEN . '/sendMessage'));

    $n = $this->notification->fresh();
    expect($n->status)->toBe('failed')
        ->and($n->last_error)->not->toContain(TestCase::FAKE_TELEGRAM_TOKEN);
});

it('tidak mengubah notifikasi yang sudah terkirim saat failed() terpanggil', function () {
    $this->notification->update(['status' => 'sent']);

    (new SendTelegramNotification($this->notification->id))->failed(new RuntimeException('x'));

    expect($this->notification->fresh()->status)->toBe('sent');
});

it('tidak mengirim ulang notifikasi yang sudah terkirim (aman bila job terduplikasi)', function () {
    Http::fake();
    $this->notification->update(['status' => 'sent']);

    runJob($this->notification);

    Http::assertNothingSent();
});

it('mengulang (bukan menggagalkan) bila token atau chat ID belum diisi, tanpa memanggil Telegram', function () {
    // Perilaku yang disengaja: dianggap error sementara. Bila .env diperbaiki dan worker
    // di-restart dalam 30 menit, notifikasi yang tertunda tetap terkirim.
    Http::fake();
    config(['services.telegram.bot_token' => null]);

    expect(fn() => runJob($this->notification))->toThrow(RuntimeException::class, 'belum diisi');

    Http::assertNothingSent();
    expect($this->notification->fresh()->status)->toBe('queued');
});

it('memakai antrean notifications, backoff bertingkat, dan batas 30 menit', function () {
    $job = new SendTelegramNotification($this->notification->id);

    expect($job->queue)->toBe('notifications')
        ->and($job->backoff())->toBe([5, 15, 30, 60, 120, 300])
        ->and($job->retryUntil()->getTimestamp())->toBe(t0()->addMinute()->addMinutes(30)->getTimestamp());
});

it('memotong pesan menjadi maksimal 4096 karakter (batas Telegram)', function () {
    Http::fake([TG_URL => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    $this->notification->update(['message' => str_repeat('x', 5000)]);

    runJob($this->notification);

    Http::assertSent(fn(Request $r) => mb_strlen($r['text']) === 4096);
});
