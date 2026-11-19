<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\SavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsGoalController extends CrudController
{
    protected string $model = SavingsGoal::class;
    protected string $resource = SavingsGoalResource::class;

    public function store(SavingsGoalRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(SavingsGoalRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }
}