<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Support\RuleLabel;
use Illuminate\Database\Seeder;

class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('monitoring.rules') as $key => $def) {
            $rule = AlertRule::firstOrNew(['key' => $key]);
            $rule->fill([
                'kind' => $def['kind'],
                'source_type' => $def['source_type'],
                'metric' => $def['metric'],
                'operator' => $def['operator'],
                'threshold' => $def['threshold'],
                'recover_operator' => $def['recover_operator'],
                'recover_threshold' => $def['recover_threshold'],
                'for_seconds' => $def['for_seconds'],
                'cooldown_seconds' => config('monitoring.default_cooldown'),
                'is_enabled' => true,
            ]);
            $rule->name = RuleLabel::make($def['label'], $rule);
            $rule->save();
        }
    }
}
