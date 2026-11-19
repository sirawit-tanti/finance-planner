<?php

namespace App\Http\Controllers\Api;

use App\Application\Transactions\TransactionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\Day;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /** Filters: month (calendar month, Y-m), category_id, payment_method, type, account_id. Newest first, paginated. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'category_id' => ['nullable', 'uuid'],
            'account_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(['income', 'expense', 'card_purchase', 'card_refund'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'transfer', 'debit', 'credit_card'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Transaction::query()->orderByDesc('txn_date')->orderByDesc('created_at');
        if (! empty($f['month'])) {
            $first = Day::parse($f['month'] . '-01');
            $query->whereBetween('txn_date', [$first->format('Y-m-d'), $first->format('Y-m-t')]);
        }
        foreach (['category_id', 'account_id', 'type', 'payment_method'] as $key) {
            if (! empty($f[$key])) {
                $query->where($key, $f[$key]);
            }
        }

        return TransactionResource::collection($query->paginate((int) ($f['per_page'] ?? 50)));
    }

    public function show(string $id): TransactionResource
    {
        return new TransactionResource($this->find($id));
    }

    public function store(TransactionRequest $request, TransactionService $service): JsonResponse
    {
        return (new TransactionResource($service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(TransactionRequest $request, string $id, TransactionService $service): TransactionResource
    {
        return new TransactionResource($service->update($this->find($id), $request->validated()));
    }

    public function destroy(string $id, TransactionService $service): Response
    {
        $service->delete($this->find($id));

        return response()->noContent();
    }

    private function find(string $id): Transaction
    {
        abort_unless(Str::isUuid($id), 404);

        return Transaction::query()->findOrFail($id);
    }
}