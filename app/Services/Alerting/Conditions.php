<?php

namespace App\Services\Alerting;

use App\Models\AlertRule;
use InvalidArgumentException;

final class Conditions
{
    private const EPSILON = 1e-9;

    public static function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '!=' => abs($value - $threshold) > self::EPSILON,
            '==' => abs($value - $threshold) <= self::EPSILON,
            default => throw new InvalidArgumentException("Operator tidak dikenal: {$operator}"),
        };
    }

    public static function breaches(AlertRule $rule, float $value): bool
    {
        return self::compare($value, $rule->operator, $rule->threshold);
    }

    public static function recovers(AlertRule $rule, float $value): bool
    {
        return self::compare($value, $rule->recover_operator, $rule->recover_threshold);
    }
}
