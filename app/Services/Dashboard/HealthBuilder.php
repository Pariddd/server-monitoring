<?php

namespace App\Services\Dashboard;

use App\Models\AlertNotification;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Kesehatan sistem pemantau itu sendiri (pemantauan diri).
 *
 * Kegagalan yang tidak terlihat dari data host:
 *  - evaluator (`schedule:work` → alerts:evaluate) berhenti → tidak ada alarm sama sekali;
 *  - worker antrean (`queue:work`) berhenti → notifikasi menumpuk dan tidak terkirim;
 *  - Telegram menolak / tidak terjangkau > 30 menit → notifikasi gagal.
 * Ditampilkan di dashboard saja: bila evaluator/worker mati, sistem tidak dapat mengirim Telegram tentang dirinya.
 */
class HealthBuilder
{
    /** Diisi AlertEngine setiap selesai evaluasi (waktu UTC, milidetik). */
    public const EVALUATOR_KEY = 'monitoring.evaluator.last_run_at';

    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $cfg = config('monitoring.health');

        // Evaluator
        $last = Cache::get(self::EVALUATOR_KEY);
        $lastAt = $last ? CarbonImmutable::parse($last, 'UTC') : null;
        $evalAge = $lastAt ? self::age($lastAt, $now) : null;
        $evaluator = [
            'last_run_at' => SummaryBuilder::isoMs($lastAt),
            'age_seconds' => $evalAge,
            'limit' => (int) $cfg['evaluator_stale_seconds'],
            'ok' => $evalAge !== null && $evalAge <= $cfg['evaluator_stale_seconds'],
        ];

        // Antrean notifikasi (termasuk yang sedang menunggu jeda retry)
        $pending = AlertNotification::where('status', 'queued')->count();
        $oldest = $pending > 0
            ? AlertNotification::where('status', 'queued')->orderBy('t_queued')->first(['t_queued', 'last_error', 'attempts'])
            : null;
        $oldestAge = $oldest ? self::age($oldest->t_queued->toImmutable(), $now) : null;
        $queue = [
            'pending' => $pending,
            'oldest_age_seconds' => $oldestAge,
            'oldest_attempts' => $oldest?->attempts,
            'last_error' => self::short($oldest?->last_error),
            'limit' => (int) $cfg['queue_stale_seconds'],
            'ok' => $pending === 0 || $oldestAge < $cfg['queue_stale_seconds'],
        ];

        // Notifikasi gagal dalam jendela waktu
        $window = (int) $cfg['failed_window_hours'];
        $failedQuery = AlertNotification::where('status', 'failed')
            ->where('t_queued', '>=', $now->subHours($window)->format('Y-m-d H:i:s.v'));
        $failedCount = (clone $failedQuery)->count();
        $lastFailed = $failedCount > 0 ? (clone $failedQuery)->orderByDesc('t_queued')->first(['t_queued', 'last_error']) : null;
        $failed = [
            'count' => $failedCount,
            'window_hours' => $window,
            'last_at' => SummaryBuilder::isoMs($lastFailed?->t_queued),
            'last_error' => self::short($lastFailed?->last_error),
            'ok' => $failedCount === 0,
        ];

        return [
            'ok' => $evaluator['ok'] && $queue['ok'] && $failed['ok'],
            'evaluator' => $evaluator,
            'queue' => $queue,
            'failed' => $failed,
        ];
    }

    private static function age(CarbonImmutable $at, CarbonImmutable $now): float
    {
        return round(max(0, $now->getTimestampMs() - $at->getTimestampMs()) / 1000, 1);
    }

    private static function short(?string $error): ?string
    {
        return $error === null ? null : mb_substr(TelegramClient::redact($error), 0, 160);
    }
}
