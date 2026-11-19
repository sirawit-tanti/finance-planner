<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountController extends CrudController
{
    protected string $model = Account::class;
    protected string $resource = AccountResource::class;

    public function store(AccountRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(AccountRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }
}