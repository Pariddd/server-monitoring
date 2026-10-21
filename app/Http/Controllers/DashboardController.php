<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\Dashboard\SourceDetailBuilder;
use App\Services\Dashboard\SummaryBuilder;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index', ['environment' => Features::environment()]);
    }

    public function summary(SummaryBuilder $builder): JsonResponse
    {
        return response()->json($builder->build());
    }

    public function show(Source $source): View
    {
        abort_unless($source->type === 'host', 404);

        return view('dashboard.show', ['source' => $source]);
    }

    public function sourceData(Request $request, Source $source, SourceDetailBuilder $builder): JsonResponse
    {
        abort_unless($source->type === 'host', 404);
        $range = array_key_exists((string) $request->query('range'), SourceDetailBuilder::RANGES)
            ? (string) $request->query('range')
            : '30m';

        return response()->json($builder->build($source, $range));
    }
}
