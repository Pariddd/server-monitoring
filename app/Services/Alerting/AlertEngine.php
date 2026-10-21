<?php

namespace App\Services\Alerting;

use App\Models\AlertEvent;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Metric;
use App\Models\Source;
use App\Jobs\SendTelegramNotification;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AlertEngine
{
    private array $stats = ['samples' => 0, 'transitions' => 0, 'notifications' => 0];

    public function __construct(
        private StateMachine $machine,
        private MessageFormatter $formatter,
    ) {}

    public function evaluate(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $this->stats = ['samples' => 0, 'transitions' => 0, 'notifications' => 0];

        // Aturan bersumber ESP32 dilewati bila fitur lingkungan dimatikan, walaupun is_enabled = 1,
        // agar tidak ada notifikasi Telegram dari fitur yang berada di luar lingkup.
        $types = Features::sourceTypes();
        $rules = AlertRule::where('is_enabled', true)->whereIn('source_type', $types)->get();
        $sources = Source::where('is_active', true)->whereIn('type', $types)->get()->groupBy('type');

        foreach ($rules as $rule) {
            foreach ($sources->get($rule->source_type, collect()) as $source) {
                $rule->kind === 'heartbeat'
                    ? $this->evaluateHeartbeat($rule, $source, $now)
                    : $this->evaluateThreshold($rule, $source, $now);
            }
        }

        return $this->stats;
    }

    private function evaluateThreshold(AlertRule $rule, Source $source, CarbonImmutable $now): void
    {
        $states = AlertState::where('alert_rule_id', $rule->id)
            ->where('source_id', $source->id)
            ->get()
            ->keyBy('tag');

        // Cari sampel sejak sampel tertua yang sudah diproses; dibatasi lookback agar data lama tidak diproses ulang.
        $lookback = $now->subSeconds(max($rule->for_seconds + 30, 120));
        $minSeen = $states
            ->map(fn(AlertState $s) => $s->last_sample_at ? CarbonImmutable::parse($s->last_sample_at, 'UTC') : null)
            ->filter()
            ->min();
        $since = ($minSeen && $minSeen->gt($lookback)) ? $minSeen : $lookback;

        $samples = Metric::where('source_id', $source->id)
            ->where('metric', $rule->metric)
            ->where('recorded_at', '>', $since)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['tag', 'value', 'recorded_at']);

        foreach ($samples as $sample) {
            $state = $states->get($sample->tag);
            if (! $state) {
                $state = new AlertState([
                    'alert_rule_id' => $rule->id,
                    'source_id' => $source->id,
                    'tag' => $sample->tag,
                    'state' => 'NORMAL',
                    'state_since' => $now,
                ]);
                $states->put($sample->tag, $state);
            }

            $recordedAt = $sample->recorded_at->toImmutable();
            if ($state->last_sample_at && $recordedAt->lte(CarbonImmutable::parse($state->last_sample_at, 'UTC'))) {
                continue; // sudah diproses atau data terlambat
            }

            $this->stats['samples']++;
            $transition = $this->machine->applySample($state, $rule, (float) $sample->value, $recordedAt);
            if ($transition) {
                $this->record($state, $rule, $source, $transition);
            }
        }

        foreach ($states as $state) {
            $this->maybeRepeat($state, $rule, $source, $now);
            $state->last_evaluated_at = $now;
            $state->save();
        }
    }

    private function evaluateHeartbeat(AlertRule $rule, Source $source, CarbonImmutable $now): void
    {
        $state = AlertState::firstOrNew(
            ['alert_rule_id' => $rule->id, 'source_id' => $source->id, 'tag' => ''],
            ['state' => 'NORMAL', 'state_since' => $now],
        );

        $lastSeen = $source->last_seen_at?->toImmutable();
        $transition = $this->machine->applyHeartbeat($state, $rule, $lastSeen, $now);
        if ($transition) {
            $this->record($state, $rule, $source, $transition);
        }

        $this->maybeRepeat($state, $rule, $source, $now);
        $state->last_evaluated_at = $now;
        $state->save();
    }

    private function record(AlertState $state, AlertRule $rule, Source $source, Transition $t): void
    {
        DB::transaction(function () use ($state, $rule, $source, $t) {
            $detectedAt = CarbonImmutable::now('UTC');

            $state->state_since = $detectedAt;
            $state->save();

            $event = AlertEvent::create([
                'alert_state_id' => $state->id,
                'from_state' => $t->from,
                'to_state' => $t->to,
                'value' => $state->last_value,
                't_breach' => $t->dataAt,
                't_detected' => $detectedAt,
            ]);
            $this->stats['transitions']++;

            if ($t->notify) {
                $this->queueNotification($state, $rule, $source, $t->notify, $t->dataAt, $detectedAt, $event->id);
            }
        });
    }

    private function maybeRepeat(AlertState $state, AlertRule $rule, Source $source, CarbonImmutable $now): void
    {
        if ($state->state !== 'FIRING' || ! $state->last_notified_at) {
            return;
        }

        $elapsed = $now->getTimestamp() - $state->last_notified_at->getTimestamp();
        if ($elapsed >= $rule->cooldown_seconds) {
            DB::transaction(fn() => $this->queueNotification(
                $state,
                $rule,
                $source,
                'REPEAT',
                $state->breach_at,
                CarbonImmutable::now('UTC'),
                null
            ));
        }
    }

    private function queueNotification(AlertState $state, AlertRule $rule, Source $source, string $kind, $dataAt, CarbonImmutable $detectedAt, ?int $eventId): void
    {
        $now = CarbonImmutable::now('UTC');

        $notification = AlertNotification::create([
            'alert_state_id' => $state->id,
            'alert_event_id' => $eventId,
            'kind' => $kind,
            'status' => 'queued',
            'message' => $this->formatter->format($kind, $rule, $source, $state->tag, $state->last_value, $dataAt),
            't_breach' => $dataAt,
            't_detected' => $detectedAt,
            't_queued' => $now,
        ]);

        SendTelegramNotification::dispatch($notification->id)->afterCommit();

        $state->last_notified_at = $now;
        $state->save();
        $this->stats['notifications']++;
    }
}
