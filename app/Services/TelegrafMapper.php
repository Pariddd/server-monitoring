<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class TelegrafMapper
{
    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}  [rows, rejected, ignored]
     */
    public function map(array $metrics, int $sourceId, CarbonImmutable $now): array
    {
        $map = config('monitoring.telegraf');
        $received = $now->format('Y-m-d H:i:s.v');
        $rows = [];
        $rejected = 0;
        $ignored = 0;

        foreach ($metrics as $m) {
            $rule = $map[$m['name']] ?? null;
            if ($rule === null) {
                $ignored++;
                continue;
            }

            $ts = (int) $m['timestamp'];
            if (! $this->timestampOk($ts, $now)) {
                $rejected++;
                continue;
            }

            $value = $m['fields'][$rule['field']] ?? null;
            if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value)) {
                $rejected++;
                continue;
            }

            $tag = '';
            if ($rule['tag_key'] !== null) {
                $tagValue = (string) ($m['tags'][$rule['tag_key']] ?? '');
                if ($rule['tag_pattern'] !== null && ! preg_match($rule['tag_pattern'], $tagValue)) {
                    $ignored++;
                    continue;
                }
                $tag = $rule['store_tag'] ? mb_substr($tagValue, 0, 64) : '';
            }

            $rows[] = [
                'source_id' => $sourceId,
                'metric' => $rule['metric'],
                'tag' => $tag,
                'value' => (float) $value,
                'recorded_at' => gmdate('Y-m-d H:i:s', $ts),
                'received_at' => $received,
            ];
        }

        return [$rows, $rejected, $ignored];
    }

    public function timestampOk(int $ts, CarbonImmutable $now): bool
    {
        $nowTs = $now->getTimestamp();

        return $ts <= $nowTs + config('monitoring.ingest.max_future_skew_seconds')
            && $ts >= $nowTs - config('monitoring.ingest.max_age_seconds');
    }
}
