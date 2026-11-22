<?php

namespace App\Console\Commands;

use App\Models\AlertRule;
use App\Models\Source;
use App\Services\Alerting\Simulation\Scenarios;
use App\Services\Alerting\Simulation\Simulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * K3 — bandingkan jumlah notifikasi aturan naif vs mesin alarm sistem pada data yang sama.
 * Hanya membaca data; tidak menulis database dan tidak mengirim Telegram.
 */
class SimulateAlerts extends Command
{
    protected $signature = 'alerts:simulate
        {--rule=cpu_high : Key aturan ambang, mis. cpu_high, mem_high, disk_low}
        {--scenario= : Skenario sintetis: lonjakan, kedip, osilasi, berkelanjutan, campuran}
        {--source= : Nama sumber (data nyata). Kosong = semua host aktif}
        {--from= : Data nyata sejak waktu UTC ini, mis. "2026-10-20 00:00:00"}
        {--to= : Data nyata sampai waktu UTC ini}
        {--csv : Simpan ringkasan ke storage/app/simulate-*.csv}';

    protected $description = 'Simulasi (replay) aturan naif vs mesin alarm sistem: jumlah notifikasi, kejadian terdeteksi, tunda deteksi';

    public function handle(Simulator $simulator): int
    {
        $rule = AlertRule::where('key', $this->option('rule'))->first();
        if (! $rule) {
            $this->error("Aturan '{$this->option('rule')}' tidak ditemukan.");
            return self::FAILURE;
        }

        try {
            $series = $this->option('scenario')
                ? ['skenario ' . $this->option('scenario') => Scenarios::make($this->option('scenario'), $rule, 1767225605)] // 2026-01-01 00:00:05 UTC
                : $this->realSeries($rule);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($series === []) {
            $this->warn('Tidak ada data metrik untuk kriteria ini.');
            return self::SUCCESS;
        }

        $totals = [];
        $episodes = ['sustained' => 0, 'transient' => 0];
        $sampleCount = 0;
        foreach ($series as $label => $samples) {
            try {
                $run = $simulator->run($rule, $samples);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
            $sampleCount += count($samples);
            foreach ($run['episodes'] as $e) {
                $episodes[$e['sustained'] ? 'sustained' : 'transient']++;
            }
            foreach ($run['results'] as $config => $r) {
                $t = $totals[$config] ?? ['total' => 0, 'alert' => 0, 'resolved' => 0, 'repeat' => 0, 'sustained_total' => 0, 'sustained_detected' => 0, 'transient_alerts' => 0, 'delay_sum' => 0, 'delay_count' => 0];
                foreach (['total', 'alert', 'resolved', 'repeat', 'sustained_total', 'sustained_detected', 'transient_alerts', 'delay_sum', 'delay_count'] as $k) {
                    $t[$k] += $r[$k];
                }
                $totals[$config] = $t;
            }
        }

        $this->info(sprintf('Aturan %s (%s %s, pulih %s %s, tahan %d dtk, cooldown %d dtk)',
            $rule->key, $rule->operator, $this->num($rule->threshold), $rule->recover_operator, $this->num($rule->recover_threshold),
            $rule->for_seconds, $rule->cooldown_seconds));
        $this->line(sprintf('Data: %s — %d deret, %d sampel. Kejadian melewati ambang: %d berkelanjutan (≥ %d dtk), %d transien.',
            $this->option('scenario') ? 'sintetis' : 'nyata', count($series), $sampleCount, $episodes['sustained'], $rule->for_seconds, $episodes['transient']));

        $rows = [];
        foreach (Simulator::CONFIGS as $config => $label) {
            $t = $totals[$config];
            $rows[] = [
                $config,
                $label,
                $t['total'],
                $t['alert'],
                $t['resolved'],
                $t['repeat'],
                $t['sustained_detected'] . '/' . $t['sustained_total'],
                $t['transient_alerts'],
                $t['delay_count'] === 0 ? '-' : number_format($t['delay_sum'] / $t['delay_count'], 1, ',', ''),
                $this->reduction($t['total'], $totals['N1']['total']),
                $this->reduction($t['total'], $totals['N2']['total']),
            ];
        }

        $headers = ['Konf.', 'Keterangan', 'Notif total', 'Pemicu', 'RESOLVED', 'REPEAT', 'Berkelanjutan terdeteksi', 'Pemicu dari transien', 'Tunda deteksi rata2 (dtk)', 'vs N1', 'vs N2'];
        $this->table($headers, $rows);
        $this->line('Pemicu = ALERT (N1) atau FIRING. "vs N1/N2" = pengurangan jumlah notifikasi total. Tunda deteksi dihitung dari awal kejadian berkelanjutan.');

        if ($this->option('csv')) {
            $path = storage_path('app/simulate-' . $rule->key . '-' . now('UTC')->format('Ymd-His') . '.csv');
            $fh = fopen($path, 'w');
            fputcsv($fh, $headers);
            foreach ($rows as $row) {
                fputcsv($fh, $row);
            }
            fclose($fh);
            $this->info("CSV: {$path}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, list<array{0:int,1:float}>> deret per sumber + tag */
    private function realSeries(AlertRule $rule): array
    {
        $sources = Source::where('type', $rule->source_type)->where('is_active', true)
            ->when($this->option('source'), fn($q, $name) => $q->where('name', $name))
            ->get();

        $series = [];
        foreach ($sources as $source) {
            $rows = DB::table('metrics')
                ->where('source_id', $source->id)
                ->where('metric', $rule->metric)
                ->when($this->option('from'), fn($q, $from) => $q->where('recorded_at', '>=', $from))
                ->when($this->option('to'), fn($q, $to) => $q->where('recorded_at', '<=', $to))
                ->orderBy('tag')->orderBy('recorded_at')
                ->get(['tag', 'value', 'recorded_at']);

            foreach ($rows->groupBy('tag') as $tag => $group) {
                $series[$source->name . ($tag !== '' ? " ({$tag})" : '')] = $group
                    ->map(fn($r) => [strtotime($r->recorded_at . ' UTC'), (float) $r->value])
                    ->all();
            }
        }

        return $series;
    }

    private function reduction(int $value, int $baseline): string
    {
        return $baseline === 0 ? '-' : number_format((1 - $value / $baseline) * 100, 1, ',', '') . '%';
    }

    private function num($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
