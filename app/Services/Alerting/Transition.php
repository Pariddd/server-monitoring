<?php

namespace App\Services\Alerting;

use Carbon\CarbonInterface;

final class Transition
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $notify,          // 'FIRING' | 'RESOLVED' | null
        public readonly ?CarbonInterface $dataAt,  // waktu data yang memicu transisi
    ) {}
}
