<?php

namespace App\Services\Alerting;

use App\Models\AlertRule;
use App\Models\AlertState;
use Carbon\CarbonImmutable;

class StateMachine
{
    /** Terapkan satu sampel aturan ambang. Mengubah $state di memori; kembalikan Transition atau null. */
    public function applySample(AlertState $state, AlertRule $rule, float $value, CarbonImmutable $recordedAt): ?Transition
    {
        $from = $state->state;
        $breach = Conditions::breaches($rule, $value);

        $state->last_value = $value;
        $state->last_sample_at = $recordedAt;

        switch ($from) {
            case 'NORMAL':
            case 'RESOLVED':
                if (! $breach) {
                    $state->state = 'NORMAL'; // RESOLVED -> NORMAL tanpa event
                    return null;
                }
                $state->breach_at = $recordedAt;
                if ($rule->for_seconds === 0) {
                    return $this->move($state, $from, 'FIRING', 'FIRING', $recordedAt);
                }
                return $this->move($state, $from, 'PENDING', null, $recordedAt);

            case 'PENDING':
                if (! $breach) {
                    $state->breach_at = null;
                    return $this->move($state, 'PENDING', 'NORMAL', null, $recordedAt);
                }
                $held = $recordedAt->getTimestamp() - $state->breach_at->getTimestamp();
                if ($held >= $rule->for_seconds) {
                    return $this->move($state, 'PENDING', 'FIRING', 'FIRING', $state->breach_at);
                }
                return null;

            case 'FIRING':
                if (Conditions::recovers($rule, $value)) {
                    return $this->move($state, 'FIRING', 'RESOLVED', 'RESOLVED', $recordedAt);
                }
                return null;
        }

        return null;
    }

    /** Evaluasi heartbeat satu sumber. */
    public function applyHeartbeat(AlertState $state, AlertRule $rule, ?CarbonImmutable $lastSeen, CarbonImmutable $now): ?Transition
    {
        if ($lastSeen === null) {
            return null; // belum pernah mengirim data
        }

        $silentSeconds = ($now->getTimestampMs() - $lastSeen->getTimestampMs()) / 1000;
        $silent = $silentSeconds > $rule->for_seconds;
        $from = $state->state;

        $state->last_value = round($silentSeconds, 1);

        if ($from === 'NORMAL' || $from === 'RESOLVED') {
            if (! $silent) {
                $state->state = 'NORMAL';
                return null;
            }
            $state->breach_at = $lastSeen;
            return $this->move($state, $from, 'FIRING', 'FIRING', $lastSeen);
        }

        if ($from === 'FIRING' && ! $silent) {
            return $this->move($state, 'FIRING', 'RESOLVED', 'RESOLVED', $lastSeen);
        }

        return null;
    }

    private function move(AlertState $state, string $from, string $to, ?string $notify, $dataAt): Transition
    {
        $state->state = $to;
        return new Transition($from, $to, $notify, $dataAt);
    }
}
