<?php

namespace App\Http\Controllers\Api;

use App\Application\Forecast\ForecastContextLoader;
use App\Application\Installments\InstallmentInput;
use App\Application\Installments\InstallmentService;
use App\Application\Installments\InstallmentSimulator;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstallmentRequest;
use App\Http\Resources\InstallmentPlanResource;
use App\Models\InstallmentPlan;
use App\Support\Day;
use Illuminate\Http\JsonResponse;

class InstallmentController extends Controller
{
    /** Calculates the schedule and the effect on future cash. Saves nothing. */
    public function simulate(InstallmentRequest $request, ForecastContextLoader $loader, InstallmentSimulator $simulator): JsonResponse
    {
        $from = $request->validated('from') ? Day::parse($request->validated('from')) : Day::today();
        $months = $request->validated('months');

        $context = $loader->load($from, $months === null ? null : (int) $months);
        $input = InstallmentInput::fromArray($request->validated());

        return response()->json(['data' => $simulator->simulate($input, $context)]);
    }

    public function store(InstallmentRequest $request, InstallmentService $service): JsonResponse
    {
        $plan = $service->create(InstallmentInput::fromArray($request->validated()));

        return (new InstallmentPlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(InstallmentPlan $plan): InstallmentPlanResource
    {
        return new InstallmentPlanResource($plan->load('scheduleItems'));
    }
}