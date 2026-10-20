<?php

namespace App\Services\Dashboard;

use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Metric;
use App\Models\Source;
use App\Services\Alerting\MessageFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SummaryBuilder
{
    public const RANK = ['FIRING' => 0, 'PENDING' => 1, 'NORMAL' => 2];

    public function __construct(private MessageFormatter $formatter) {}

    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        $sources = Source::where('is_active', true)->orderBy('name')->get();
        $ids = $sources->pluck('id');

        $states = AlertState::with('rule')
            ->whereIn('source_id', $ids)
            ->get()
            ->filter(fn($s) => $s->rule && $s->rule->is_enabled);
        $statesBySource = $states->groupBy('source_id');

        $recent = Metric::whereIn('source_id', $ids)
            ->where('recorded_at', '>', $now->subMinutes(5))
            ->orderBy('recorded_at')
            ->get(['source_id', 'metric', 'tag', 'value', 'recorded_at'])
            ->groupBy('source_id');

        $hosts = $sources->where('type', 'host')
            ->map(fn($s) => $this->hostCard($s, $statesBySource->get($s->id, collect()), $recent->get($s->id, collect()), $now))
            ->sortBy([['rank', 'asc'], ['name', 'asc']])
            ->values();

        $esp = $sources->firstWhere('type', 'esp32');
        $room = $esp
            ? $this->roomCard($esp, $statesBySource->get($esp->id, collect()), $recent->get($esp->id, collect()), $now)
            : null;

        $alerts = $states
            ->filter(fn($s) => in_array($s->state, ['FIRING', 'PENDING'], true))
            ->map(fn($s) => $this->alertItem($s, $sources->firstWhere('id', $s->source_id), $now))
            ->sortBy([['rank', 'asc'], ['since', 'desc']])
            ->values();

        return [
            'generated_at' => self::iso($now),
            'thresholds' => AlertRule::whereIn('key', ['cpu_high', 'mem_high', 'disk_low'])->get()
                ->mapWithKeys(fn($r) => [['cpu_high' => 'cpu', 'mem_high' => 'mem', 'disk_low' => 'disk'][$r->key] => $r->threshold]),
            'disabled_rules' => AlertRule::where('is_enabled', false)->orderBy('id')->pluck('name'),
            'counts' => [
                'total' => $hosts->count(),
                'FIRING' => $hosts->where('state', 'FIRING')->count(),
                'PENDING' => $hosts->where('state', 'PENDING')->count(),
                'NORMAL' => $hosts->where('state', 'NORMAL')->count(),
            ],
            'hosts' => $hosts,
            'room' => $room,
            'alerts' => $alerts,
        ];
    }

    private function hostCard(Source $source, Collection $states, Collection $metrics, CarbonImmutable $now): array
    {
        $latest = $metrics->groupBy(fn($r) => $r->metric . '|' . $r->tag)->map(fn($g) => $g->last());
        $of = fn(string $metric) => $latest->filter(fn($r) => $r->metric === $metric)->values();

        $cpu = $of('cpu_usage')->first();
        $mem = $of('mem_used_pct')->first();
        $disks = $of('disk_free_pct')
            ->sortBy('tag')
            ->map(fn($r) => ['volume' => $r->tag, 'free' => round($r->value, 1)])
            ->values();
        $services = $of('service_state');

        $state = self::worst($states);
        $top = $states
            ->filter(fn($s) => in_array($s->state, ['FIRING', 'PENDING'], true))
            ->sortBy(fn($s) => self::RANK[$s->state])
            ->first();

        return [
            'id' => $source->id,
            'name' => $source->name,
            'ip' => $source->ip_address,
            'state' => $state,
            'rank' => self::RANK[$state],
            'cpu' => $cpu ? round($cpu->value, 1) : null,
            'cpu_spark' => $metrics->where('metric', 'cpu_usage')->take(-18)->pluck('value')->map(fn($v) => round($v, 1))->values(),
            'mem' => $mem ? round($mem->value, 1) : null,
            'disk' => $disks->sortBy('free')->first(),
            'disks' => $disks,
            'services' => [
                'running' => $services->filter(fn($r) => (int) $r->value === 4)->count(),
                'total' => $services->count(),
            ],
            'seen_seconds' => self::seenSeconds($source, $now),
            'last_seen_at' => self::isoMs($source->last_seen_at),
            'note' => $top ? $top->rule->name . ' · ' . $this->formatter->formatValue($top->rule, $top->last_value) : null,
        ];
    }

    private function roomCard(Source $source, Collection $states, Collection $metrics, CarbonImmutable $now): array
    {
        $rules = AlertRule::whereIn('key', ['temp_high', 'humidity_high'])->get()->keyBy('metric');
        $stateByMetric = $states->keyBy(fn($s) => $s->rule->metric ?? 'heartbeat');

        $block = function (string $metric) use ($metrics, $rules, $stateByMetric, $now) {
            $rows = $metrics->where('metric', $metric)->values();
            $rule = $rules->get($metric);
            $st = $stateByMetric->get($metric);

            return [
                'value' => $rows->last() ? round($rows->last()->value, 1) : null,
                'spark' => $rows->take(-18)->pluck('value')->map(fn($v) => round($v, 1))->values(),
                'state' => $st ? self::normalize($st->state) : 'NORMAL',
                'threshold' => $rule?->threshold,
                'recover' => $rule?->recover_threshold,
                'pending' => $st && $st->state === 'PENDING' ? self::pending($st, $now) : null,
            ];
        };

        return [
            'name' => $source->name,
            'ip' => $source->ip_address,
            'seen_seconds' => self::seenSeconds($source, $now),
            'heartbeat_limit' => AlertRule::where('key', 'heartbeat_esp32')->value('for_seconds'),
            'temperature' => $block('temperature'),
            'humidity' => $block('humidity'),
        ];
    }

    private function alertItem(AlertState $st, Source $source, CarbonImmutable $now): array
    {
        $cooldownLeft = null;
        if ($st->state === 'FIRING' && $st->last_notified_at) {
            $cooldownLeft = max(0, $st->rule->cooldown_seconds - ($now->getTimestamp() - $st->last_notified_at->getTimestamp()));
        }

        return [
            'id' => $st->id,
            'rule' => $st->rule->name,
            'key' => $st->rule->key,
            'state' => $st->state,
            'rank' => self::RANK[$st->state],
            'source' => $source->name,
            'source_type' => $source->type,
            'ip' => $source->ip_address,
            'tag' => $st->tag,
            'value' => $this->formatter->formatValue($st->rule, $st->last_value),
            'breach_at' => self::iso($st->breach_at),
            'since' => self::iso($st->state_since),
            'pending' => $st->state === 'PENDING' ? self::pending($st, $now) : null,
            'cooldown_left' => $cooldownLeft,
        ];
    }

    public static function worst(Collection $states): string
    {
        return $states->map(fn($s) => self::normalize($s->state))
            ->sortBy(fn($s) => self::RANK[$s])
            ->first() ?? 'NORMAL';
    }

    public static function normalize(string $state): string
    {
        return $state === 'RESOLVED' ? 'NORMAL' : $state;
    }

    public static function pending(AlertState $st, CarbonImmutable $now): ?array
    {
        if (! $st->breach_at) {
            return null;
        }
        $for = $st->rule->for_seconds;
        $held = min($for, max(0, $now->getTimestamp() - $st->breach_at->getTimestamp()));

        return ['held' => $held, 'for' => $for];
    }

    public static function seenSeconds(Source $source, CarbonImmutable $now): ?float
    {
        return $source->last_seen_at
            ? round(($now->getTimestampMs() - $source->last_seen_at->getTimestampMs()) / 1000, 1)
            : null;
    }

    public static function iso($time): ?string
    {
        return $time ? CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    public static function isoMs($time): ?string
    {
        return $time ? CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s.v\Z') : null;
    }
}
