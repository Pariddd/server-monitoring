<?php

namespace App\Services\Alerting\Simulation;

use App\Models\AlertRule;
use InvalidArgumentException;

/**
 * Skenario sintetis dengan kebenaran dasar (ground truth) yang diketahui, relatif terhadap ambang aturan.
 * Satu sampel tiap 10 dtk (sama dengan interval Telegraf). Nilai "buruk" = melewati ambang pemicu,
 * nilai "abu-abu" = di antara ambang pemicu dan ambang pulih.
 *
 *  lonjakan       : 6 lonjakan singkat 30 dtk (3 sampel), jarak 5 mnt.                  → 0 berkelanjutan, 6 transien
 *  kedip          : 10 mnt nilai bergantian buruk/abu-abu tiap sampel.                  → 0 berkelanjutan, 30 transien
 *  osilasi        : 6 siklus [buruk 60 dtk (7 sampel), abu-abu 10 dtk], lalu normal.    → 6 berkelanjutan
 *  berkelanjutan  : 3 kejadian buruk 5, 5, dan 25 mnt, jarak 20 mnt.                   → 3 berkelanjutan
 *  campuran       : semua skenario di atas berurutan.
 */
final class Scenarios
{
    public const NAMES = ['lonjakan', 'kedip', 'osilasi', 'berkelanjutan', 'campuran'];

    private const STEP = 10;

    /** @return list<array{0:int,1:float}> */
    public static function make(string $name, AlertRule $rule, int $startTs): array
    {
        if (! in_array($rule->operator, ['>', '<'], true)) {
            throw new InvalidArgumentException('Skenario sintetis hanya untuk aturan dengan operator > atau <.');
        }

        $sign = $rule->operator === '>' ? 1 : -1;
        $t = (float) $rule->threshold;
        $r = (float) $rule->recover_threshold;
        $bad = $t + $sign * 7;
        $grey = ($t + $r) / 2;
        $normal = $t - $sign * 45;

        $values = match ($name) {
            'lonjakan' => self::lonjakan($normal, $bad),
            'kedip' => self::kedip($normal, $bad, $t - $sign * 1),
            'osilasi' => self::osilasi($normal, $bad, $grey),
            'berkelanjutan' => self::berkelanjutan($normal, $bad),
            'campuran' => array_merge(
                self::lonjakan($normal, $bad),
                self::kedip($normal, $bad, $t - $sign * 1),
                self::osilasi($normal, $bad, $grey),
                self::berkelanjutan($normal, $bad),
            ),
            default => throw new InvalidArgumentException('Skenario tidak dikenal: ' . $name . '. Pilih: ' . implode(', ', self::NAMES)),
        };

        $samples = [];
        foreach ($values as $i => $v) {
            $samples[] = [$startTs + $i * self::STEP, round(max(0, $v), 2)];
        }

        return $samples;
    }

    private static function repeat(float $v, int $n): array
    {
        return array_fill(0, $n, $v);
    }

    private static function lonjakan(float $normal, float $bad): array
    {
        $v = self::repeat($normal, 6);
        for ($i = 0; $i < 6; $i++) {
            $v = array_merge($v, self::repeat($bad, 3), self::repeat($normal, 27)); // 30 dtk buruk, 4,5 mnt normal
        }

        return $v;
    }

    private static function kedip(float $normal, float $bad, float $justBelow): array
    {
        $v = self::repeat($normal, 6);
        for ($i = 0; $i < 30; $i++) {
            $v[] = $bad;
            $v[] = $justBelow;
        }

        return array_merge($v, self::repeat($normal, 12));
    }

    private static function osilasi(float $normal, float $bad, float $grey): array
    {
        $v = self::repeat($normal, 6);
        for ($i = 0; $i < 6; $i++) {
            $v = array_merge($v, self::repeat($bad, 7), [$grey]);
        }

        return array_merge($v, self::repeat($normal, 12));
    }

    private static function berkelanjutan(float $normal, float $bad): array
    {
        $v = self::repeat($normal, 6);
        foreach ([30, 30, 150] as $n) { // 5, 5, 25 mnt (tiap sampel 10 dtk)
            $v = array_merge($v, self::repeat($bad, $n), self::repeat($normal, 120));
        }

        return $v;
    }
}
