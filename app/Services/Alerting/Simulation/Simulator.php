<?php

namespace App\Services\Alerting\Simulation;

use App\Models\AlertRule;
use App\Models\AlertState;
use App\Services\Alerting\Conditions;
use App\Services\Alerting\StateMachine;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Simulasi (replay) satu deret sampel terhadap beberapa konfigurasi aturan — kebaruan K3.
 * Murni di memori: tidak menulis database dan tidak mengirim Telegram.
 *
 * Konfigurasi:
 *   N1  naif per sampel   : pesan untuk SETIAP sampel yang melewati ambang.
 *   N2  naif persilangan  : FIRING saat nilai melewati ambang, RESOLVED saat kembali (tanpa durasi tahan/histeresis).
 *   S1  + durasi tahan    : mesin status sistem, ambang pulih = ambang pemicu (tanpa histeresis), tanpa REPEAT.
 *   S2  + histeresis      : mesin status sistem dengan ambang pulih aturan, tanpa REPEAT.
 *   S3  sistem lengkap    : S2 + pengingat REPEAT tiap cooldown (perilaku sistem sebenarnya).
 *
 * "Kejadian" = rangkaian sampel berturut-turut yang melewati ambang (putus bila jeda antarsampel > $maxGap).
 *   berkelanjutan : durasi (sampel terakhir − pertama) >= durasi tahan aturan;
 *   transien      : lebih pendek dari durasi tahan.
 * Catatan: ini definisi operasional berbasis durasi tahan, bukan label "masalah nyata"; untuk kebenaran
 * dasar (ground truth) yang independen gunakan skenario sintetis (Scenarios).
 */
final class Simulator
{
    public const CONFIGS = [
        'N1' => 'Naif: setiap sampel di atas ambang',
        'N2' => 'Naif: setiap persilangan ambang',
        'S1' => '+ durasi tahan',
        'S2' => '+ durasi tahan + histeresis',
        'S3' => 'Sistem: + histeresis + pengingat (cooldown)',
    ];

    private const INVERSE = ['>' => '<', '<' => '>', '!=' => '=='];

    public function __construct(private StateMachine $machine) {}

    /**
     * @param  list<array{0:int,1:float}>  $samples  [epoch detik UTC, nilai], urut waktu naik
     * @return array{episodes: array, results: array<string, array>}
     */
    public function run(AlertRule $rule, array $samples, int $maxGap = 30): array
    {
        if ($rule->kind !== 'threshold') {
            throw new InvalidArgumentException('Simulasi hanya untuk aturan ambang (threshold), bukan heartbeat.');
        }

        $episodes = $this->episodes($rule, $samples, $maxGap);
        $results = [];
        foreach (array_keys(self::CONFIGS) as $config) {
            $notifications = match ($config) {
                'N1' => $this->naivePerSample($rule, $samples),
                'N2' => $this->naiveCrossing($rule, $samples),
                'S1' => $this->machine($this->withoutHysteresis($rule), $samples, false),
                'S2' => $this->machine($rule, $samples, false),
                'S3' => $this->machine($rule, $samples, true),
            };
            $results[$config] = $this->score($notifications['list'], $notifications['active'], $episodes);
        }

        return ['episodes' => $episodes, 'results' => $results];
    }

    /** Rangkaian sampel yang melewati ambang. */
    public function episodes(AlertRule $rule, array $samples, int $maxGap = 30): array
    {
        $episodes = [];
        $current = null;
        $prevTs = null;

        foreach ($samples as [$ts, $value]) {
            $breach = Conditions::breaches($rule, (float) $value);
            $gap = $prevTs !== null && ($ts - $prevTs) > $maxGap;

            if ($current !== null && (! $breach || $gap)) {
                $episodes[] = $this->closeEpisode($current, $rule);
                $current = null;
            }
            if ($breach) {
                $current ??= ['start' => $ts, 'end' => $ts, 'samples' => 0];
                $current['end'] = $ts;
                $current['samples']++;
            }
            $prevTs = $ts;
        }
        if ($current !== null) {
            $episodes[] = $this->closeEpisode($current, $rule);
        }

        return $episodes;
    }

    private function closeEpisode(array $e, AlertRule $rule): array
    {
        $e['duration'] = $e['end'] - $e['start'];
        $e['sustained'] = $e['duration'] >= $rule->for_seconds;

        return $e;
    }

    /** @return array{list: list<array>, active: array<int, bool>} */
    private function naivePerSample(AlertRule $rule, array $samples): array
    {
        $list = [];
        $active = [];
        foreach ($samples as [$ts, $value]) {
            $breach = Conditions::breaches($rule, (float) $value);
            $active[$ts] = $breach;
            if ($breach) {
                $list[] = ['kind' => 'ALERT', 'at' => $ts];
            }
        }

        return ['list' => $list, 'active' => $active];
    }

    private function naiveCrossing(AlertRule $rule, array $samples): array
    {
        $list = [];
        $active = [];
        $firing = false;
        foreach ($samples as [$ts, $value]) {
            $breach = Conditions::breaches($rule, (float) $value);
            if ($breach && ! $firing) {
                $list[] = ['kind' => 'FIRING', 'at' => $ts];
                $firing = true;
            } elseif (! $breach && $firing) {
                $list[] = ['kind' => 'RESOLVED', 'at' => $ts];
                $firing = false;
            }
            $active[$ts] = $firing;
        }

        return ['list' => $list, 'active' => $active];
    }

    /** Mesin status yang sama dengan sistem (StateMachine), dijalankan di memori. */
    private function machine(AlertRule $rule, array $samples, bool $repeat): array
    {
        $state = new AlertState(['state' => 'NORMAL']);
        $list = [];
        $active = [];
        $lastNotified = null;

        foreach ($samples as [$ts, $value]) {
            $t = $this->machine->applySample($state, $rule, (float) $value, CarbonImmutable::createFromTimestamp($ts, 'UTC'));
            if ($t && $t->notify) {
                $list[] = ['kind' => $t->notify, 'at' => $ts];
                $lastNotified = $ts;
            } elseif ($repeat && $state->state === 'FIRING' && $lastNotified !== null && ($ts - $lastNotified) >= $rule->cooldown_seconds) {
                $list[] = ['kind' => 'REPEAT', 'at' => $ts];
                $lastNotified = $ts;
            }
            $active[$ts] = $state->state === 'FIRING';
        }

        return ['list' => $list, 'active' => $active];
    }

    /** Salinan aturan dengan ambang pulih = ambang pemicu (tanpa histeresis). */
    private function withoutHysteresis(AlertRule $rule): AlertRule
    {
        $copy = $rule->replicate();
        $copy->recover_operator = self::INVERSE[$rule->operator];
        $copy->recover_threshold = $rule->threshold;

        return $copy;
    }

    private function score(array $list, array $active, array $episodes): array
    {
        $count = fn(string $kind) => count(array_filter($list, fn($n) => $n['kind'] === $kind));
        $alerts = array_values(array_filter($list, fn($n) => in_array($n['kind'], ['ALERT', 'FIRING'], true)));

        $sustained = array_values(array_filter($episodes, fn($e) => $e['sustained']));
        $detected = 0;
        $delays = [];
        foreach ($sustained as $e) {
            $isActive = false;
            foreach ($active as $ts => $on) {
                if ($ts >= $e['start'] && $ts <= $e['end'] && $on) {
                    $isActive = true;
                    break;
                }
            }
            $detected += $isActive ? 1 : 0;

            // Tunda deteksi: pemicu baru (ALERT/FIRING) pertama di dalam kejadian.
            foreach ($alerts as $n) {
                if ($n['at'] >= $e['start'] && $n['at'] <= $e['end']) {
                    $delays[] = $n['at'] - $e['start'];
                    break;
                }
            }
        }

        $transientAlerts = 0;
        foreach ($alerts as $n) {
            foreach ($episodes as $e) {
                if (! $e['sustained'] && $n['at'] >= $e['start'] && $n['at'] <= $e['end']) {
                    $transientAlerts++;
                    break;
                }
            }
        }

        return [
            'total' => count($list),
            'alert' => $count('ALERT') + $count('FIRING'),
            'resolved' => $count('RESOLVED'),
            'repeat' => $count('REPEAT'),
            'sustained_total' => count($sustained),
            'sustained_detected' => $detected,
            'transient_alerts' => $transientAlerts,
            'mean_delay' => $delays === [] ? null : array_sum($delays) / count($delays),
            'delay_sum' => array_sum($delays),
            'delay_count' => count($delays),
            'notifications' => $list,
        ];
    }
}
