<?php

namespace App\Http\Resources;

use App\Domain\Finance\CreditCards\StatementCalculator;
use App\Support\Day;
use Illuminate\Http\Request;

class StatementResource extends ModelResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $calculator = new StatementCalculator();
        $summary = $calculator->summarize(
            (string) $s->purchases_total, (string) $s->installments_total, (string) $s->interest_charged,
            (string) $s->fees_charged, (string) $s->refunds_total, (string) $s->carried_over, (string) $s->paid_amount,
        );

        return parent::toArray($request) + [
            'outstanding' => (string) $summary->outstanding,
            'is_overdue' => $calculator->isOverdue($summary, Day::of($s->due_date), Day::today()),
            'payments' => $this->whenLoaded('payments', fn () => $s->payments
                ->sortBy('payment_date')
                ->map(fn ($p) => (new ModelResource($p))->resolve())->values()->all()),
        ];
    }
}