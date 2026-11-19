<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryController extends CrudController
{
    protected string $model = Category::class;
    protected string $resource = CategoryResource::class;

    public function store(CategoryRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(CategoryRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }
}