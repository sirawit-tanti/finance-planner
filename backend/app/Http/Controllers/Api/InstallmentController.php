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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstallmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InstallmentPlanResource::collection(InstallmentPlan::query()->orderByDesc('created_at')->get());
    }

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
        $plan = $service->create(InstallmentInput::fromArray($request->validated()), $request->validated('category_id'));

        return (new InstallmentPlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(InstallmentPlan $plan): InstallmentPlanResource
    {
        return new InstallmentPlanResource($plan->load('scheduleItems'));
    }

    public function cancel(InstallmentPlan $plan, InstallmentService $service): InstallmentPlanResource
    {
        return new InstallmentPlanResource($service->cancel($plan));
    }
}