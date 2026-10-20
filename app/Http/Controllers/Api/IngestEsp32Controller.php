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

class IngestEsp32Controller extends Controller
{
    public function __invoke(Request $request, TelegrafMapper $mapper, SourcePresence $presence): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'temperature' => ['required', 'numeric', 'between:-40,80'],
            'humidity' => ['required', 'numeric', 'between:0,100'],
            'ts' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Payload tidak valid.', 'error' => $validator->errors()->first()], 422);
        }

        $now = CarbonImmutable::now('UTC');
        $ts = (int) $request->input('ts');

        if (! $mapper->timestampOk($ts, $now)) {
            return response()->json(['message' => 'Stempel waktu di luar rentang. Periksa sinkronisasi NTP.'], 422);
        }

        $source = $request->attributes->get('source');
        $base = [
            'source_id' => $source->id,
            'tag' => '',
            'recorded_at' => gmdate('Y-m-d H:i:s', $ts),
            'received_at' => $now->format('Y-m-d H:i:s.v'),
        ];

        $stored = Metric::insertOrIgnore([
            $base + ['metric' => 'temperature', 'value' => (float) $request->input('temperature')],
            $base + ['metric' => 'humidity', 'value' => (float) $request->input('humidity')],
        ]);

        $presence->touch($source, $request, $now);

        return response()->json(['stored' => $stored]);
    }
}
