<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Services\SourcePresence;
use App\Services\TelegrafMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IngestTelegrafController extends Controller
{
    public function __invoke(Request $request, TelegrafMapper $mapper, SourcePresence $presence): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'metrics' => ['required', 'array', 'max:' . config('monitoring.ingest.max_metrics_per_request')],
            'metrics.*.name' => ['required', 'string', 'max:64'],
            'metrics.*.fields' => ['required', 'array'],
            'metrics.*.tags' => ['nullable', 'array'],
            'metrics.*.timestamp' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Payload tidak valid.',
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $source = $request->attributes->get('source');
        $now = CarbonImmutable::now('UTC');
        $metrics = $request->input('metrics');

        [$rows, $rejected, $ignored] = $mapper->map($metrics, $source->id, $now);

        $stored = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $stored += Metric::insertOrIgnore($chunk);
        }

        $presence->touch($source, $request, $now);

        return response()->json([
            'received' => count($metrics),
            'stored' => $stored,
            'duplicates' => count($rows) - $stored,
            'ignored' => $ignored,
            'rejected' => $rejected,
        ]);
    }
}
