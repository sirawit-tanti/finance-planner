<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** index / show / destroy for a user-owned model. Children implement store/update with their own FormRequest. */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var class-string<JsonResource> */
    protected string $resource;

    public function index(): AnonymousResourceCollection
    {
        return $this->resource::collection($this->model::query()->orderBy('created_at')->get());
    }

    public function show(string $id): JsonResource
    {
        return new ($this->resource)($this->find($id));
    }

    public function destroy(string $id): Response
    {
        $this->find($id)->delete();

        return response()->noContent();
    }

    protected function find(string $id): Model
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->model::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    protected function created(array $data): JsonResponse
    {
        $model = new $this->model();
        $model->fill($data)->save();

        return (new ($this->resource)($model->refresh()))->response()->setStatusCode(201);
    }

    /** @param array<string, mixed> $data */
    protected function updated(string $id, array $data): JsonResource
    {
        $model = $this->find($id);
        $model->fill($data)->save();

        return new ($this->resource)($model->refresh());
    }
}