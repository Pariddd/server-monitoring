<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use Illuminate\Database\Seeder;

class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'key' => 'cpu_high',
                'name' => 'CPU > 85% selama 60 dtk',
                'kind' => 'threshold',
                'source_type' => 'host',
                'metric' => 'cpu_usage',
                'operator' => '>',
                'threshold' => 85,
                'recover_operator' => '<',
                'recover_threshold' => 80,
                'for_seconds' => 60
            ],
            [
                'key' => 'mem_high',
                'name' => 'Memori terpakai > 90% selama 60 dtk',
                'kind' => 'threshold',
                'source_type' => 'host',
                'metric' => 'mem_used_pct',
                'operator' => '>',
                'threshold' => 90,
                'recover_operator' => '<',
                'recover_threshold' => 85,
                'for_seconds' => 60
            ],
            [
                'key' => 'disk_low',
                'name' => 'Ruang disk bebas < 10%',
                'kind' => 'threshold',
                'source_type' => 'host',
                'metric' => 'disk_free_pct',
                'operator' => '<',
                'threshold' => 10,
                'recover_operator' => '>',
                'recover_threshold' => 12,
                'for_seconds' => 0
            ],
            [
                'key' => 'service_down',
                'name' => 'Layanan tidak running selama 20 dtk',
                'kind' => 'threshold',
                'source_type' => 'host',
                'metric' => 'service_state',
                'operator' => '!=',
                'threshold' => 4,
                'recover_operator' => '==',
                'recover_threshold' => 4,
                'for_seconds' => 20
            ],
            [
                'key' => 'temp_high',
                'name' => 'Suhu > 27 °C selama 60 dtk',
                'kind' => 'threshold',
                'source_type' => 'esp32',
                'metric' => 'temperature',
                'operator' => '>',
                'threshold' => 27,
                'recover_operator' => '<',
                'recover_threshold' => 26,
                'for_seconds' => 60
            ],
            [
                'key' => 'humidity_high',
                'name' => 'Kelembaban > 60% RH selama 60 dtk',
                'kind' => 'threshold',
                'source_type' => 'esp32',
                'metric' => 'humidity',
                'operator' => '>',
                'threshold' => 60,
                'recover_operator' => '<',
                'recover_threshold' => 58,
                'for_seconds' => 60
            ],
            [
                'key' => 'heartbeat_host',
                'name' => 'Host tanpa data > 30 dtk',
                'kind' => 'heartbeat',
                'source_type' => 'host',
                'metric' => null,
                'operator' => null,
                'threshold' => null,
                'recover_operator' => null,
                'recover_threshold' => null,
                'for_seconds' => 30
            ],
            [
                'key' => 'heartbeat_esp32',
                'name' => 'ESP32 tanpa data > 90 dtk',
                'kind' => 'heartbeat',
                'source_type' => 'esp32',
                'metric' => null,
                'operator' => null,
                'threshold' => null,
                'recover_operator' => null,
                'recover_threshold' => null,
                'for_seconds' => 90
            ],
        ];

        foreach ($rules as $rule) {
            AlertRule::updateOrCreate(
                ['key' => $rule['key']],
                $rule + ['cooldown_seconds' => 600, 'is_enabled' => true]
            );
        }
    }
}
