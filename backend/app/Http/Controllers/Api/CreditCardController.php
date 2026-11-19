<?php

namespace App\Http\Controllers\Api;

use App\Application\Statements\StatementRecalculator;
use App\Http\Requests\CreditCardRequest;
use App\Http\Resources\CreditCardResource;
use App\Models\CreditCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class CreditCardController extends CrudController
{
    protected string $model = CreditCard::class;
    protected string $resource = CreditCardResource::class;

    public function store(CreditCardRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    /** The opening balance feeds the first statement, so statements are recalculated after a change. */
    public function update(CreditCardRequest $request, string $id, StatementRecalculator $recalculator): JsonResource
    {
        return DB::transaction(function () use ($request, $id, $recalculator): JsonResource {
            $card = $this->find($id);
            $card->fill($request->validated())->save();
            $recalculator->recalculateCard($card);

            return new CreditCardResource($card->refresh());
        });
    }
}