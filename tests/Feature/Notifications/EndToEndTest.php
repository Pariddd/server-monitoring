<?php

use App\Models\AlertNotification;
use App\Models\Metric;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
| Alur lengkap tanpa pemalsuan antrean (QUEUE_CONNECTION=sync):
| data Telegraf masuk lewat API → alerts:evaluate → job → Telegram (dipalsukan).
*/

it('mengalirkan data layanan berhenti sampai pesan FIRING dan RESOLVED terkirim', function () {
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 101]])
        ->push(['ok' => true, 'result' => ['message_id' => 102]])]);
    seedRules();
    [, $token] = makeSource('h1', 'host');

    $send = function (int $state, int $offset) use ($token) {
        freezeAt(t0()->addSeconds($offset + 1));
        $this->postJson('/api/ingest/telegraf', ['metrics' => [[
            'name' => 'win_services',
            'tags' => ['service_name' => 'Spooler'],
            'fields' => ['state' => $state],
            'timestamp' => t0()->addSeconds($offset)->getTimestamp(),
        ]]], ['Authorization' => 'Bearer ' . $token])->assertOk();
    };
    $evaluate = function (int $offset) {
        freezeAt(t0()->addSeconds($offset));
        expect(Artisan::call('alerts:evaluate'))->toBe(0);
    };

    foreach ([0 => 4, 10 => 1, 20 => 1, 30 => 1, 40 => 4] as $offset => $state) {
        $send($state, $offset);
        $evaluate($offset + 5);
    }

    expect(Metric::count())->toBe(5)
        ->and(AlertNotification::orderBy('id')->get(['kind', 'status', 'telegram_message_id'])->toArray())->toBe([
            ['kind' => 'FIRING', 'status' => 'sent', 'telegram_message_id' => 101],
            ['kind' => 'RESOLVED', 'status' => 'sent', 'telegram_message_id' => 102],
        ]);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $r) => str_starts_with($r['text'], '[FIRING] Layanan tidak running selama 20 dtk'));
    Http::assertSent(fn(Request $r) => str_starts_with($r['text'], '[RESOLVED] Layanan tidak running selama 20 dtk'));
});
