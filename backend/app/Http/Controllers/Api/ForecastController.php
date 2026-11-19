<?php

namespace App\Http\Controllers\Api;

use App\Application\Forecast\ForecastSnapshotService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForecastRequest;
use App\Support\Day;
use Illuminate\Http\JsonResponse;

class ForecastController extends Controller
{
    public function show(ForecastRequest $request, ForecastSnapshotService $service): JsonResponse
    {
        $from = $request->validated('from') ? Day::parse($request->validated('from')) : Day::today();
        $months = $request->validated('months');

        [$payload, $cached] = $service->get($from, $months === null ? null : (int) $months, $request->boolean('refresh'));

        return response()->json(['data' => $payload, 'cached' => $cached]);
    }
}