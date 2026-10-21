<?php

use App\Models\AlertEvent;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Metric;
use App\Models\Source;
use App\Services\Alerting\AlertEngine;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Konfigurasi tes
|--------------------------------------------------------------------------
| Semua tes Feature memakai TestCase proyek (guard database _test, token
| Telegram palsu, blokir HTTP) dan RefreshDatabase (migrasi ulang database
| tes; tiap tes berjalan dalam transaksi yang di-rollback).
| Tes Unit tidak memakai aplikasi Laravel dan database.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helper bersama
|--------------------------------------------------------------------------
*/

/** Waktu acuan tes (UTC). Detik :05 meniru collection_offset = "5s" Telegraf. */
function t0(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-21 10:00:05', 'UTC');
}

/** Bekukan jam aplikasi (now(), Carbon, CarbonImmutable) pada waktu tertentu. */
function freezeAt(CarbonImmutable|string $at): CarbonImmutable
{
    $at = $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse($at, 'UTC');
    Carbon::setTestNow($at);
    CarbonImmutable::setTestNow($at);

    return $at;
}

/**
 * Isi tabel alert_rules dari config/monitoring.php (sama seperti sistem asli).
 * Semua aturan aktif. $overrides: ['cpu_high' => ['for_seconds' => 0], ...]
 *
 * @return \Illuminate\Support\Collection<string, AlertRule>
 */
function seedRules(array $overrides = []): Illuminate\Support\Collection
{
    (new AlertRuleSeeder)->run();

    foreach ($overrides as $key => $values) {
        AlertRule::where('key', $key)->firstOrFail()->update($values);
    }

    return AlertRule::orderBy('id')->get()->keyBy('key');
}

/** Ambil satu aturan berdasarkan key. */
function rule(string $key): AlertRule
{
    return AlertRule::where('key', $key)->firstOrFail();
}

/**
 * Buat sumber data beserta token asli (plain). Mengembalikan [Source, token].
 *
 * @return array{0: Source, 1: string}
 */
function makeSource(string $name = 'h1', string $type = 'host', bool $active = true): array
{
    $token = 'pm_' . bin2hex(random_bytes(32));
    $source = Source::create([
        'name' => $name,
        'type' => $type,
        'token_hash' => Source::hashToken($token),
        'is_active' => $active,
    ]);

    return [$source, $token];
}

/** Masukkan satu sampel metrik langsung ke database (melewati API). */
function sample(Source $source, string $metric, float $value, CarbonImmutable $at, string $tag = ''): void
{
    Metric::insert([
        'source_id' => $source->id,
        'metric' => $metric,
        'tag' => $tag,
        'value' => $value,
        'recorded_at' => $at->format('Y-m-d H:i:s'),
        'received_at' => $at->addSecond()->format('Y-m-d H:i:s.v'),
    ]);
}

/**
 * Masukkan deret sampel tiap $step detik: $values[0] pada $start, $values[1] pada $start+$step, dst.
 * Mengembalikan waktu sampel terakhir.
 */
function series(Source $source, string $metric, array $values, CarbonImmutable $start, string $tag = '', int $step = 10): CarbonImmutable
{
    $at = $start;
    foreach (array_values($values) as $i => $value) {
        $at = $start->addSeconds($i * $step);
        sample($source, $metric, (float) $value, $at, $tag);
    }

    return $at;
}

/** Jalankan evaluator satu kali dengan jam aplikasi dibekukan pada $now. */
function evaluateAt(CarbonImmutable $now): array
{
    freezeAt($now);

    return app(AlertEngine::class)->evaluate($now);
}

/** Urutan transisi "FROM>TO" untuk satu aturan + tag, sesuai urutan kejadian. */
function transitions(string $ruleKey, string $tag = ''): array
{
    return AlertEvent::query()
        ->join('alert_states as s', 's.id', '=', 'alert_events.alert_state_id')
        ->join('alert_rules as r', 'r.id', '=', 's.alert_rule_id')
        ->where('r.key', $ruleKey)
        ->where('s.tag', $tag)
        ->orderBy('alert_events.id')
        ->get(['from_state', 'to_state'])
        ->map(fn($e) => "{$e->from_state}>{$e->to_state}")
        ->all();
}

/** State satu aturan + tag (null bila belum pernah dievaluasi). */
function stateOf(string $ruleKey, string $tag = ''): ?AlertState
{
    return AlertState::where('alert_rule_id', rule($ruleKey)->id)->where('tag', $tag)->first();
}

/** Jenis notifikasi (FIRING/RESOLVED/REPEAT) satu aturan, sesuai urutan antre. */
function notificationKinds(string $ruleKey): array
{
    return AlertNotification::query()
        ->join('alert_states as s', 's.id', '=', 'alert_notifications.alert_state_id')
        ->join('alert_rules as r', 'r.id', '=', 's.alert_rule_id')
        ->where('r.key', $ruleKey)
        ->orderBy('alert_notifications.id')
        ->pluck('alert_notifications.kind')
        ->all();
}

/**
 * Payload form halaman Aturan (PUT /rules) persis seperti yang dikirim browser,
 * dibangun dari isi database. $changes: ['cpu_high' => ['threshold' => 90, 'is_enabled' => '0'], ...]
 */
function rulesPayload(array $changes = []): array
{
    $rows = [];
    foreach (AlertRule::orderBy('id')->get() as $rule) {
        $row = [
            'for_seconds' => (string) $rule->for_seconds,
            'cooldown_seconds' => (string) $rule->cooldown_seconds,
            'is_enabled' => $rule->is_enabled ? '1' : '0',
        ];
        if ($rule->kind === 'threshold') {
            $row['threshold'] = (string) $rule->threshold;
            $row['recover_threshold'] = (string) $rule->recover_threshold;
        }
        $rows[$rule->id] = array_merge($row, array_map('strval', $changes[$rule->key] ?? []));
    }

    return ['rules' => $rows];
}
