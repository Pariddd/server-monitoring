<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LatencyReport extends Command
{
    protected $signature = 'alerts:latency
        {--since= : Hanya notifikasi sejak waktu UTC ini, mis. "2026-10-21 08:00:00"}
        {--rule= : Hanya satu aturan, mis. service_down}
        {--csv : Simpan data per notifikasi ke storage/app/latency-*.csv}';

    protected $description = 'Statistik latensi notifikasi (tanpa REPEAT dan tanpa kondisi awal)';

    public function handle(): int
    {
        $rows = DB::table('alert_notifications as n')
            ->join('alert_states as s', 's.id', '=', 'n.alert_state_id')
            ->join('alert_rules as r', 'r.id', '=', 's.alert_rule_id')
            ->join('sources as src', 'src.id', '=', 's.source_id')
            ->leftJoin('metrics as m', function ($j) {
                $j->on('m.source_id', '=', 's.source_id')
                    ->on('m.metric', '=', 'r.metric')
                    ->on('m.tag', '=', 's.tag')
                    ->on('m.recorded_at', '=', 'n.t_breach');
            })
            ->where('n.status', 'sent')
            ->whereIn('n.kind', ['FIRING', 'RESOLVED'])
            ->whereNotNull('n.t_breach')
            ->whereColumn('n.t_breach', '>=', 's.created_at') // buang kondisi awal
            ->when($this->option('since'), fn($q, $since) => $q->where('n.t_queued', '>=', $since))
            ->when($this->option('rule'), fn($q, $rule) => $q->where('r.key', $rule))
            ->orderBy('n.id')
            ->get([
                'n.id',
                'n.kind',
                'r.key as rule',
                'r.for_seconds',
                'src.name as source',
                's.tag',
                'n.t_breach',
                'm.received_at',
                'n.t_detected',
                'n.t_queued',
                'n.t_sent',
            ]);

        if ($rows->isEmpty()) {
            $this->warn('Tidak ada notifikasi yang memenuhi kriteria.');
            return self::SUCCESS;
        }

        $sec = fn(?string $t) => $t ? CarbonImmutable::parse($t, 'UTC')->getTimestampMs() / 1000 : null;

        $data = $rows->map(function ($r) use ($sec) {
            $hold = $r->kind === 'FIRING' ? (int) $r->for_seconds : 0;
            $breach = $sec($r->t_breach);
            $received = $sec($r->received_at);
            $detected = $sec($r->t_detected);
            $queued = $sec($r->t_queued);
            $sent = $sec($r->t_sent);

            return [
                'id' => $r->id,
                'kind' => $r->kind,
                'rule' => $r->rule,
                'source' => $r->source,
                'tag' => $r->tag,
                'hold' => $hold,
                'A_ingest' => $received !== null ? $received - $breach : null,
                'B_evaluasi' => $detected - ($breach + $hold),
                'C_antre' => $queued - $detected,
                'D_kirim' => $sent - $queued,
                'sistem' => $sent - $breach - $hold,
                'total' => $sent - $breach,
            ];
        });

        $table = [];
        foreach (['A_ingest', 'B_evaluasi', 'C_antre', 'D_kirim', 'sistem', 'total'] as $col) {
            $values = $data->pluck($col)->filter(fn($v) => $v !== null)->values()->all();
            $table[] = array_merge([$col], $this->stats($values));
        }

        $this->info('Latensi (detik), ' . $data->count() . ' notifikasi');
        $this->table(['Ruas', 'n', 'min', 'rata-rata', 'median', 'p95', 'maks'], $table);

        if ($data->count() < 20) {
            $this->warn('n < 20: p95 belum bermakna secara statistik. Tambah jumlah percobaan.');
        }

        if ($this->option('csv')) {
            $path = storage_path('app/latency-' . now('UTC')->format('Ymd-His') . '.csv');
            $fh = fopen($path, 'w');
            fputcsv($fh, array_keys($data->first()));
            foreach ($data as $row) {
                fputcsv($fh, array_map(fn($v) => is_float($v) ? round($v, 3) : $v, $row));
            }
            fclose($fh);
            $this->info("CSV: {$path}");
        }

        return self::SUCCESS;
    }

    /** [n, min, mean, median, p95 (nearest-rank), max] */
    private function stats(array $v): array
    {
        $n = count($v);
        if ($n === 0) {
            return [0, '-', '-', '-', '-', '-'];
        }

        sort($v);
        $median = $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
        $p95 = $v[max(0, (int) ceil(0.95 * $n) - 1)];
        $f = fn($x) => number_format($x, 3, ',', '');

        return [$n, $f($v[0]), $f(array_sum($v) / $n), $f($median), $f($p95), $f($v[$n - 1])];
    }
}
