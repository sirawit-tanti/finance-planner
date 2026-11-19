<?php

namespace App\Http\Controllers\Api;

use App\Application\Payments\CardPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardPaymentRequest;
use App\Http\Resources\ModelResource;
use App\Http\Resources\StatementResource;
use App\Models\CreditCardPayment;
use App\Models\CreditCardStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CardPaymentController extends Controller
{
    public function store(CardPaymentRequest $request, CreditCardStatement $statement, CardPaymentService $service): JsonResponse
    {
        $payment = $service->pay($statement, $request->validated());
        $fresh = CreditCardStatement::query()->findOrFail($statement->id)->load('payments');

        return response()->json([
            'data' => (new ModelResource($payment->refresh()))->resolve(),
            'statement' => (new StatementResource($fresh))->resolve(),
        ], 201);
    }

    public function destroy(string $payment, CardPaymentService $service): Response
    {
        abort_unless(Str::isUuid($payment), 404);
        $service->delete(CreditCardPayment::query()->findOrFail($payment));

        return response()->noContent();
    }
}