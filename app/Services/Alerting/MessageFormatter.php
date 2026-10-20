<?php

namespace App\Services\Alerting;

use App\Models\AlertRule;
use App\Models\Source;
use Carbon\CarbonInterface;

class MessageFormatter
{
    private const SERVICE_STATES = [
        1 => 'Stopped',
        2 => 'Start pending',
        3 => 'Stop pending',
        4 => 'Running',
        5 => 'Continue pending',
        6 => 'Pause pending',
        7 => 'Paused',
    ];

    public function format(string $kind, AlertRule $rule, Source $source, string $tag, ?float $value, ?CarbonInterface $dataAt): string
    {
        $title = match ($kind) {
            'FIRING' => '[FIRING]',
            'RESOLVED' => '[RESOLVED]',
            'REPEAT' => '[MASIH FIRING]',
        };

        $timeLabel = $kind === 'RESOLVED' ? 'Waktu data pulih' : 'Waktu data melewati ambang';
        if ($rule->kind === 'heartbeat') {
            $timeLabel = 'Data terakhir diterima';
        }

        return implode("\n", [
            "{$title} {$rule->name}",
            'Sumber: ' . $source->name . ($tag !== '' ? " ({$tag})" : ''),
            'Nilai: ' . $this->value($rule, $value),
            $timeLabel . ': ' . ($dataAt ? $dataAt->copy()->utc()->format('Y-m-d H:i:s') . 'Z' : '-'),
        ]);
    }

    private function value(AlertRule $rule, ?float $v): string
    {
        if ($v === null) {
            return '-';
        }

        $num = fn(float $x) => number_format($x, 1, ',', '');

        return match ($rule->metric) {
            'cpu_usage', 'mem_used_pct', 'disk_free_pct' => $num($v) . '%',
            'temperature' => $num($v) . ' °C',
            'humidity' => $num($v) . '% RH',
            'service_state' => (self::SERVICE_STATES[(int) $v] ?? 'Unknown') . ' (state=' . (int) $v . ')',
            null => 'tanpa data ' . (int) round($v) . ' dtk (batas ' . $rule->for_seconds . ' dtk)',
            default => $num($v),
        };
    }
}
