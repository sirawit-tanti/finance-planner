<?php

namespace App\Http\Controllers\Api;

use App\Application\Forecast\ForecastContextLoader;
use App\Application\Forecast\ForecastRunner;
use App\Application\Forecast\ForecastSerializer;
use App\Application\WhatIf\ScenarioChangeFactory;
use App\Domain\Finance\WhatIf\ScenarioComparator;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatIfRequest;
use App\Support\Day;
use Illuminate\Http\JsonResponse;

class WhatIfController extends Controller
{
    /** Runs the forecast twice (as is / with the changes) and compares. Never writes financial data. */
    public function run(
        WhatIfRequest $request,
        ForecastContextLoader $loader,
        ScenarioChangeFactory $factory,
        ForecastRunner $runner,
        ForecastSerializer $serializer,
    ): JsonResponse {
        $from = $request->validated('from') ? Day::parse($request->validated('from')) : Day::today();
        $months = $request->validated('months');

        $context = $loader->load($from, $months === null ? null : (int) $months);
        $plan = $factory->build($request->input('changes'), $context, (string) $request->user()->id);

        $before = $runner->run($context);
        $after = $runner->run($context, $plan->changes, $plan->extraCharges);

        return response()->json(['data' => [
            'from' => $context->from->format('Y-m-d'),
            'horizon_months' => $context->months,
            'before' => $serializer->summary($before),
            'after' => $serializer->summary($after),
            'comparison' => $serializer->comparison((new ScenarioComparator())->compare($before, $after)),
        ]]);
    }
}