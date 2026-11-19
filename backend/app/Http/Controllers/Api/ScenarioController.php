<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ScenarioRequest;
use App\Http\Resources\ScenarioResource;
use App\Models\Scenario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ScenarioController extends CrudController
{
    protected string $model = Scenario::class;
    protected string $resource = ScenarioResource::class;

    public function store(ScenarioRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(ScenarioRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }
}