<?php

namespace App\Console\Commands;

use App\Services\Alerting\AlertEngine;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluasi semua aturan alert terhadap data terbaru';

    public function handle(AlertEngine $engine): int
    {
        $stats = $engine->evaluate();
        $this->line(json_encode($stats));

        return self::SUCCESS;
    }
}
