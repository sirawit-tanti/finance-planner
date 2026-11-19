<?php

namespace App\Http\Controllers\Api;

use App\Application\Payments\RecurringSettlementService;
use App\Http\Requests\RecurringExpenseRequest;
use App\Http\Resources\RecurringExpenseResource;
use App\Http\Resources\TransactionResource;
use App\Models\RecurringExpense;
use App\Support\Day;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringExpenseController extends CrudController
{
    protected string $model = RecurringExpense::class;
    protected string $resource = RecurringExpenseResource::class;

    public function store(RecurringExpenseRequest $request): JsonResponse
    {
        return $this->created($request->validated());
    }

    public function update(RecurringExpenseRequest $request, string $id): JsonResource
    {
        return $this->updated($id, $request->validated());
    }

    /** Records that one occurrence was paid. Amount defaults to the override for that occurrence, then the rule's amount. */
    public function settle(Request $request, string $id, RecurringSettlementService $service): JsonResponse
    {
        $rule = $this->find($id);
        $v = $request->validate([
            'occurrence_date' => ['required', 'date_format:Y-m-d'],
            'txn_date' => ['nullable', 'date_format:Y-m-d', Rules::notFuture()],
            'amount' => ['nullable', Rules::MONEY, 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $transaction = $service->settleExpense(
            $rule, $v['occurrence_date'], $v['txn_date'] ?? Day::today()->format('Y-m-d'),
            isset($v['amount']) ? (string) $v['amount'] : null, $v['note'] ?? null,
        );

        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }
}