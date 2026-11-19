<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\PlannedItemRequest;
use App\Http\Resources\PlannedItemResource;
use App\Models\PlannedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class PlannedItemController extends CrudController
{
    protected string $model = PlannedItem::class;
    protected string $resource = PlannedItemResource::class;

    public function store(PlannedItemRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(PlannedItemRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }
}