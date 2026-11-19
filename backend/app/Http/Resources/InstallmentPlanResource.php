<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallmentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'note' => $this->note,
            'interest_method' => $this->interest_method,
            'annual_rate_percent' => $this->annual_rate_percent,
            'price' => $this->price,
            'down_payment' => $this->down_payment,
            'financed_amount' => $this->financed_amount,
            'term_count' => $this->term_count,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date->format('Y-m-d'),
            'first_due_date' => $this->first_due_date->format('Y-m-d'),
            'fee_amount' => $this->fee_amount,
            'fee_mode' => $this->fee_mode,
            'funding_type' => $this->funding_type,
            'credit_card_id' => $this->credit_card_id,
            'account_id' => $this->account_id,
            'total_interest' => $this->total_interest,
            'total_payable' => $this->total_payable,
            'status' => $this->status,
            'schedule' => $this->whenLoaded('scheduleItems', fn () => $this->scheduleItems->map(fn ($item) => [
                'id' => $item->id,
                'installment_no' => $item->installment_no,
                'due_date' => $item->due_date->format('Y-m-d'),
                'principal' => $item->principal_part,
                'interest' => $item->interest_part,
                'fee' => $item->fee_part,
                'total_due' => $item->total_due,
                'remaining_principal' => $item->remaining_principal_after,
                'status' => $item->status,
            ])->values()->all()),
        ];
    }
}