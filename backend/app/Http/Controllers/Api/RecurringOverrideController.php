<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RecurringOverrideRequest;
use App\Http\Resources\ModelResource;
use App\Models\RecurringOverride;
use Illuminate\Http\JsonResponse;

class RecurringOverrideController extends CrudController
{
    protected string $model = RecurringOverride::class;
    protected string $resource = ModelResource::class;

    /** One override per occurrence: sending it again updates the existing one (201 when created, 200 when updated). */
    public function store(RecurringOverrideRequest $request): JsonResponse
    {
        $v = $request->validated();
        $existing = RecurringOverride::query()
            ->where('source_type', $v['source_type'])->where('source_id', $v['source_id'])
            ->whereDate('occurrence_date', $v['occurrence_date'])->first();

        if ($existing === null) {
            return $this->created($v);
        }

        $existing->fill($v)->save();

        return (new ModelResource($existing->refresh()))->response()->setStatusCode(200);
    }
}