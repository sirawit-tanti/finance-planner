<?php

namespace App\Http\Controllers\Api;

use App\Application\Payments\RecurringSettlementService;
use App\Http\Requests\IncomeSourceRequest;
use App\Http\Resources\IncomeSourceResource;
use App\Http\Resources\TransactionResource;
use App\Models\IncomeSource;
use App\Support\Day;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomeSourceController extends CrudController
{
    protected string $model = IncomeSource::class;
    protected string $resource = IncomeSourceResource::class;

    public function store(IncomeSourceRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(IncomeSourceRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }

    /** Records that one occurrence was received (the forecast then stops expecting it). */
    public function receive(Request $request, string $id, RecurringSettlementService $service): JsonResponse
    {
        $source = $this->find($id);
        $v = $request->validate([
            'occurrence_date' => ['required', 'date_format:Y-m-d'],
            'txn_date' => ['nullable', 'date_format:Y-m-d', Rules::notFuture()],
            'amount' => ['nullable', Rules::MONEY, 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $transaction = $service->receiveIncome(
            $source, $v['occurrence_date'], $v['txn_date'] ?? Day::today()->format('Y-m-d'),
            isset($v['amount']) ? (string) $v['amount'] : null, $v['note'] ?? null,
        );

        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }
}