<?php

namespace App\Support;

use App\Models\AlertRule;

final class RuleLabel
{
    /** Template: ':t' ambang, ':f' durasi, ':dur' = " selama N dtk" atau kosong bila 0. */
    public static function make(string $template, AlertRule $rule): string
    {
        $dur = $rule->for_seconds > 0 ? ' selama ' . $rule->for_seconds . ' dtk' : '';

        return str_replace(
            [':t', ':dur', ':f'],
            [self::number($rule->threshold), $dur, (string) $rule->for_seconds],
            $template,
        );
    }

    /** 85 -> "85", 27.5 -> "27,5" */
    public static function number($value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');
    }
}
