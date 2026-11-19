<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavingsContributionRequest;
use App\Http\Resources\ModelResource;
use App\Models\SavingsContribution;
use App\Models\SavingsGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SavingsContributionController extends Controller
{
    public function index(string $goal): AnonymousResourceCollection
    {
        $goal = $this->goal($goal);

        return ModelResource::collection(
            SavingsContribution::query()->where('goal_id', $goal->id)->orderByDesc('contribution_date')->get()
        );
    }

    public function store(SavingsContributionRequest $request, string $goal): JsonResponse
    {
        $goal = $this->goal($goal);
        $contribution = new SavingsContribution();
        $contribution->fill($request->validated() + ['goal_id' => $goal->id])->save();

        return (new ModelResource($contribution->refresh()))->response()->setStatusCode(201);
    }

    public function destroy(string $contribution): Response
    {
        abort_unless(Str::isUuid($contribution), 404);
        SavingsContribution::query()->findOrFail($contribution)->delete();

        return response()->noContent();
    }

    private function goal(string $id): SavingsGoal
    {
        abort_unless(Str::isUuid($id), 404);

        return SavingsGoal::query()->findOrFail($id);
    }
}