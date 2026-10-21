<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\AlertState;
use App\Support\RuleLabel;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class RuleController extends Controller
{
    public function edit(): View
    {
        return view('rules.edit', [
            'rules' => AlertRule::orderBy('id')->get()->keyBy('key'),
            'defaults' => config('monitoring.rules'),
            'defaultCooldown' => config('monitoring.default_cooldown'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $defs = config('monitoring.rules');
        $rules = AlertRule::whereIn('key', array_keys($defs))->get()->keyBy('id');
        $input = (array) $request->input('rules', []);

        $validator = Validator::make($request->all(), [
            'rules' => ['required', 'array'],
            'rules.*.threshold' => ['nullable', 'numeric'],
            'rules.*.recover_threshold' => ['nullable', 'numeric'],
            'rules.*.for_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'rules.*.cooldown_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'rules.*.is_enabled' => ['nullable', 'boolean'],
        ], [], [
            'rules.*.for_seconds' => 'durasi',
            'rules.*.cooldown_seconds' => 'cooldown',
        ]);

        $validator->after(function ($v) use ($input, $rules, $defs) {
            foreach ($input as $id => $row) {
                $rule = $rules->get((int) $id);
                if (! $rule) {
                    $v->errors()->add("rules.$id", 'Aturan tidak dikenal.');
                    continue;
                }
                $def = $defs[$rule->key];
                $for = (int) ($row['for_seconds'] ?? 0);

                if ($rule->kind === 'heartbeat') {
                    if ($for < $def['min_for']) {
                        $v->errors()->add("rules.$id.for_seconds", "Minimal {$def['min_for']} dtk (2× interval kirim) agar tidak salah alarm.");
                    }
                    continue;
                }

                if ($for > 0 && $for < 10) {
                    $v->errors()->add("rules.$id.for_seconds", 'Isi 0 (langsung FIRING) atau minimal 10 dtk (1× interval Telegraf).');
                }

                if (! ($def['editable_threshold'] ?? true)) {
                    continue;
                }

                $t = $row['threshold'] ?? null;
                $r = $row['recover_threshold'] ?? null;
                if (! is_numeric($t) || ! is_numeric($r)) {
                    $v->errors()->add("rules.$id.threshold", 'Ambang pemicu dan ambang pulih wajib berupa angka.');
                    continue;
                }
                $t = (float) $t;
                $r = (float) $r;

                foreach (['threshold' => $t, 'recover_threshold' => $r] as $field => $value) {
                    if ($value < $def['min'] || $value > $def['max']) {
                        $v->errors()->add("rules.$id.$field", "Harus di antara {$def['min']} dan {$def['max']}.");
                    }
                }

                if ($def['operator'] === '>' && $r >= $t) {
                    $v->errors()->add("rules.$id.recover_threshold", 'Untuk aturan ">", ambang pulih harus lebih kecil dari ambang pemicu (' . RuleLabel::number($t) . ').');
                }
                if ($def['operator'] === '<' && $r <= $t) {
                    $v->errors()->add("rules.$id.recover_threshold", 'Untuk aturan "<", ambang pulih harus lebih besar dari ambang pemicu (' . RuleLabel::number($t) . ').');
                }
            }
        });

        $validator->validate(); // gagal: kembali ke form dengan pesan error dan isian lama

        DB::transaction(function () use ($input, $rules, $defs) {
            $disabled = [];
            $enabled = [];

            foreach ($input as $id => $row) {
                $rule = $rules->get((int) $id);
                $def = $defs[$rule->key];
                $wasEnabled = (bool) $rule->is_enabled;

                $rule->for_seconds = (int) $row['for_seconds'];
                $rule->cooldown_seconds = (int) $row['cooldown_seconds'];
                $rule->is_enabled = (bool) ($row['is_enabled'] ?? false);

                if ($rule->kind === 'threshold' && ($def['editable_threshold'] ?? true)) {
                    $rule->threshold = (float) $row['threshold'];
                    $rule->recover_threshold = (float) $row['recover_threshold'];
                }

                $rule->name = RuleLabel::make($def['label'], $rule);
                $rule->save();

                if ($wasEnabled && ! $rule->is_enabled) {
                    $disabled[] = $rule->id;
                } elseif (! $wasEnabled && $rule->is_enabled) {
                    $enabled[] = $rule->id;
                }
            }

            $this->resetStates($disabled, skipBacklog: false);
            $this->resetStates($enabled, skipBacklog: true);
        });

        return redirect()->route('rules.edit')
            ->with('status', 'Perubahan disimpan. Berlaku pada evaluasi berikutnya (paling lambat 10 dtk).');
    }

    public function reset(): RedirectResponse
    {
        DB::transaction(function () {
            // Seeder mengaktifkan semua aturan; aturan yang tadinya nonaktif harus mulai dari data baru.
            $previouslyDisabled = AlertRule::where('is_enabled', false)->pluck('id')->all();

            (new AlertRuleSeeder)->run();

            $nowEnabled = AlertRule::whereIn('id', $previouslyDisabled)
                ->where('is_enabled', true)
                ->pluck('id')
                ->all();

            $this->resetStates($nowEnabled, skipBacklog: true);
        });

        return redirect()->route('rules.edit')
            ->with('status', 'Semua aturan dikembalikan ke parameter skripsi dan diaktifkan.');
    }

    /**
     * Kembalikan state aturan ke NORMAL.
     *
     * Saat dinonaktifkan: cukup NORMAL (aturan tidak dievaluasi).
     * Saat diaktifkan kembali (skipBacklog): geser penanda last_sample_at ke "sekarang"
     * agar evaluator hanya memproses sampel yang datang setelah aturan aktif.
     * Tanpa ini, sampel selama masa nonaktif yang masih masuk jendela lookback
     * akan dievaluasi ulang dan memicu FIRING/RESOLVED untuk kejadian lampau.
     *
     * @param  array<int>  $ruleIds
     */
    private function resetStates(array $ruleIds, bool $skipBacklog): void
    {
        if ($ruleIds === []) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');

        $values = [
            'state' => 'NORMAL',
            'breach_at' => null,
            'state_since' => $now,
        ];

        if ($skipBacklog) {
            $values['last_sample_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        }
        AlertState::whereIn('alert_rule_id', $ruleIds)->update($values);
    }
}
