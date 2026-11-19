<?php

namespace App\Http\Controllers\Api;

use App\Application\Payments\InstallmentPaymentService;
use App\Domain\Finance\Installments\PayoffQuote;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstallmentPaymentRequest;
use App\Http\Requests\PayoffQuoteRequest;
use App\Http\Resources\ModelResource;
use App\Models\InstallmentPlan;
use Illuminate\Http\JsonResponse;

class InstallmentPaymentController extends Controller
{
    public function quote(PayoffQuoteRequest $request, InstallmentPlan $plan, InstallmentPaymentService $service): JsonResponse
    {
        $quote = $service->quote(
            $plan, (string) ($request->validated('interest_discount') ?? '0'), (string) ($request->validated('early_fee') ?? '0')
        );

        return response()->json(['data' => $this->present($quote)]);
    }

    public function pay(InstallmentPaymentRequest $request, InstallmentPlan $plan, InstallmentPaymentService $service): JsonResponse
    {
        $payment = $service->payRow($plan, $request->validated('schedule_item_id'), $request->validated('payment_date'));

        return response()->json([
            'data' => (new ModelResource($payment->refresh()))->resolve(),
            'plan_status' => $plan->refresh()->status,
        ], 201);
    }

    public function payoff(PayoffQuoteRequest $request, InstallmentPlan $plan, InstallmentPaymentService $service): JsonResponse
    {
        $payment = $service->payoff(
            $plan, $request->validated('payment_date'),
            (string) ($request->validated('interest_discount') ?? '0'), (string) ($request->validated('early_fee') ?? '0'),
        );

        return response()->json([
            'data' => (new ModelResource($payment->refresh()))->resolve(),
            'plan_status' => $plan->refresh()->status,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function present(PayoffQuote $q): array
    {
        return [
            'remaining_principal' => (string) $q->remainingPrincipal,
            'remaining_interest' => (string) $q->remainingInterest,
            'remaining_fees' => (string) $q->remainingFees,
            'scheduled_remaining' => (string) $q->scheduledRemaining,
            'interest_discount' => (string) $q->interestDiscount,
            'early_fee' => (string) $q->earlyFee,
            'payoff_amount' => (string) $q->payoffAmount,
            'remaining_installments' => $q->remainingInstallments,
        ];
    }
}