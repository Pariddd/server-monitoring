<?php

namespace App\Services\Dashboard;

use App\Models\AlertEvent;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Metric;
use App\Models\Source;
use App\Services\Alerting\MessageFormatter;
use Carbon\CarbonImmutable;

class SourceDetailBuilder
{
    public const RANGES = ['30m' => [1800, null], '3h' => [10800, 60], '24h' => [86400, 600]];

    private const SERVICE_STATES = [1 => 'Stopped', 2 => 'Start pending', 3 => 'Stop pending', 4 => 'Running', 5 => 'Continue pending', 6 => 'Pause pending', 7 => 'Paused'];

    public function __construct(private MessageFormatter $formatter) {}

    public function build(Source $source, string $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        [$span, $bucket] = self::RANGES[$range];
        $from = $now->subSeconds($span);

        $states = AlertState::with('rule')->where('source_id', $source->id)->get()
            ->filter(fn($s) => $s->rule && $s->rule->is_enabled);
        $rules = AlertRule::whereIn('key', ['cpu_high', 'mem_high', 'disk_low', 'service_down'])->get()->keyBy('key');

        return [
            'generated_at' => SummaryBuilder::iso($now),
            'range' => $range,
            'from_ms' => $from->getTimestampMs(),
            'to_ms' => $now->getTimestampMs(),
            'source' => [
                'name' => $source->name,
                'type' => $source->type,
                'ip' => $source->ip_address,
                'ip_updated_at' => SummaryBuilder::iso($source->ip_updated_at),
                'seen_seconds' => SummaryBuilder::seenSeconds($source, $now),
                'last_seen_at' => SummaryBuilder::isoMs($source->last_seen_at),
                'state' => SummaryBuilder::worst($states),
                'heartbeat_limit' => AlertRule::where('key', 'heartbeat_host')->value('for_seconds'),
            ],
            'series' => [
                'cpu' => $this->series($source, 'cpu_usage', $from, $bucket),
                'mem' => $this->series($source, 'mem_used_pct', $from, $bucket),
            ],
            'thresholds' => [
                'cpu' => ['fire' => $rules['cpu_high']->threshold ?? null, 'recover' => $rules['cpu_high']->recover_threshold ?? null],
                'mem' => ['fire' => $rules['mem_high']->threshold ?? null, 'recover' => $rules['mem_high']->recover_threshold ?? null],
                'disk' => ['fire' => $rules['disk_low']->threshold ?? null, 'recover' => $rules['disk_low']->recover_threshold ?? null],
            ],
            'disabled_rules' => AlertRule::where('is_enabled', false)->orderBy('id')->pluck('name'),
            'breaches' => $this->breaches($source, $from),
            'disks' => $this->disks($source, $states, $now, (bool) ($rules['disk_low']->is_enabled ?? true)),
            'services' => $this->services($source, $states, $now, (bool) ($rules['service_down']->is_enabled ?? true)),
            'alerts' => $states->filter(fn($s) => in_array($s->state, ['FIRING', 'PENDING'], true))
                ->map(fn($s) => $this->alertItem($s, $now))
                ->values(),
            'events' => $this->events($source),
        ];
    }

    private function alertItem(AlertState $s, CarbonImmutable $now): array
    {
        $notif = $s->state === 'FIRING'
            ? AlertNotification::where('alert_state_id', $s->id)->where('kind', 'FIRING')->latest('id')->first()
            : null;
        $cooldownLeft = ($s->state === 'FIRING' && $s->last_notified_at)
            ? max(0, $s->rule->cooldown_seconds - ($now->getTimestamp() - $s->last_notified_at->getTimestamp()))
            : null;

        return [
            'id' => $s->id,
            'rule' => $s->rule->name,
            'state' => $s->state,
            'tag' => $s->tag,
            'value' => $this->formatter->formatValue($s->rule, $s->last_value),
            'recover' => $s->rule->recover_threshold,
            'breach_at' => SummaryBuilder::iso($s->breach_at),
            'since' => SummaryBuilder::iso($s->state_since),
            'pending' => $s->state === 'PENDING' ? SummaryBuilder::pending($s, $now) : null,
            'cooldown_left' => $cooldownLeft,
            'notification' => $notif ? [
                'status' => $notif->status,
                't_breach' => SummaryBuilder::iso($notif->t_breach),
                't_detected' => SummaryBuilder::isoMs($notif->t_detected),
                't_queued' => SummaryBuilder::isoMs($notif->t_queued),
                't_sent' => SummaryBuilder::isoMs($notif->t_sent),
                'message_id' => $notif->telegram_message_id,
            ] : null,
        ];
    }

    /** @return array<int, array{0:int,1:float}> [epoch_ms, nilai] */
    private function series(Source $source, string $metric, CarbonImmutable $from, ?int $bucket): array
    {
        $base = Metric::where('source_id', $source->id)
            ->where('metric', $metric)
            ->where('tag', '')
            ->where('recorded_at', '>=', $from);

        if ($bucket === null) {
            return $base->orderBy('recorded_at')->get(['recorded_at', 'value'])
                ->map(fn($r) => [$r->recorded_at->getTimestampMs(), round($r->value, 2)])
                ->all();
        }

        return $base->selectRaw('FLOOR(UNIX_TIMESTAMP(recorded_at) / ?) * ? AS t, MAX(value) AS v', [$bucket, $bucket])
            ->groupBy('t')
            ->orderBy('t')
            ->get()
            ->map(fn($r) => [(int) $r->t * 1000, round((float) $r->v, 2)])
            ->all();
    }

    private function breaches(Source $source, CarbonImmutable $from): array
    {
        return AlertEvent::query()
            ->join('alert_states as s', 's.id', '=', 'alert_events.alert_state_id')
            ->join('alert_rules as r', 'r.id', '=', 's.alert_rule_id')
            ->where('s.source_id', $source->id)
            ->whereIn('r.key', ['cpu_high', 'mem_high'])
            ->where('alert_events.to_state', 'FIRING')
            ->where('alert_events.t_breach', '>=', $from)
            ->get(['r.key', 'alert_events.t_breach'])
            ->map(fn($e) => [
                'series' => $e->key === 'cpu_high' ? 'cpu' : 'mem',
                't_ms' => CarbonImmutable::parse($e->t_breach, 'UTC')->getTimestampMs(),
                'iso' => SummaryBuilder::iso($e->t_breach),
            ])
            ->all();
    }

    private function disks(Source $source, $states, CarbonImmutable $now, bool $enabled): array
    {
        $diskStates = $states->filter(fn($s) => $s->rule->key === 'disk_low')->keyBy('tag');

        return Metric::where('source_id', $source->id)
            ->where('metric', 'disk_free_pct')
            ->where('recorded_at', '>', $now->subMinutes(5))
            ->orderBy('recorded_at')
            ->get(['tag', 'value'])
            ->groupBy('tag')
            ->map(fn($g, $tag) => [
                'volume' => $tag,
                'free' => round($g->last()->value, 1),
                'state' => ! $enabled ? 'NONAKTIF'
                    : (isset($diskStates[$tag]) ? SummaryBuilder::normalize($diskStates[$tag]->state) : 'NORMAL'),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    private function services(Source $source, $states, CarbonImmutable $now, bool $enabled): array
    {
        $svcStates = $states->filter(fn($s) => $s->rule->key === 'service_down')->keyBy('tag');

        $latest = Metric::where('source_id', $source->id)
            ->where('metric', 'service_state')
            ->where('recorded_at', '>', $now->subMinutes(5))
            ->orderBy('recorded_at')
            ->get(['tag', 'value', 'recorded_at'])
            ->groupBy('tag')
            ->map(fn($g) => $g->last());

        return $latest->map(function ($row, $tag) use ($source, $svcStates, $enabled) {
            // Perkiraan waktu status berubah: sampel pertama setelah sampel terakhir yang nilainya berbeda.
            $lastDifferent = Metric::where('source_id', $source->id)
                ->where('metric', 'service_state')
                ->where('tag', $tag)
                ->where('value', '!=', $row->value)
                ->max('recorded_at');
            $changedAt = $lastDifferent
                ? Metric::where('source_id', $source->id)->where('metric', 'service_state')->where('tag', $tag)
                ->where('recorded_at', '>', $lastDifferent)->min('recorded_at')
                : null;

            return [
                'name' => $tag,
                'status' => self::SERVICE_STATES[(int) $row->value] ?? 'Unknown',
                'running' => (int) $row->value === 4,
                'state' => ! $enabled ? 'NONAKTIF'
                    : (isset($svcStates[$tag]) ? SummaryBuilder::normalize($svcStates[$tag]->state) : 'NORMAL'),
                'changed_at' => SummaryBuilder::iso($changedAt),
            ];
        })->sortKeys()->values()->all();
    }

    private function events(Source $source): array
    {
        return AlertEvent::query()
            ->join('alert_states as s', 's.id', '=', 'alert_events.alert_state_id')
            ->join('alert_rules as r', 'r.id', '=', 's.alert_rule_id')
            ->where('s.source_id', $source->id)
            ->whereIn('alert_events.to_state', ['FIRING', 'RESOLVED'])
            ->orderByDesc('alert_events.id')
            ->limit(20)
            ->get(['r.name as rule', 's.tag', 'alert_events.from_state', 'alert_events.to_state', 'alert_events.value', 'alert_events.t_breach', 'alert_events.t_detected'])
            ->map(fn($e) => [
                'rule' => $e->rule,
                'tag' => $e->tag,
                'from' => $e->from_state,
                'to' => $e->to_state,
                'value' => $e->value !== null ? round($e->value, 1) : null,
                't_breach' => SummaryBuilder::iso($e->t_breach),
                't_detected' => SummaryBuilder::iso($e->t_detected),
            ])
            ->all();
    }
}
