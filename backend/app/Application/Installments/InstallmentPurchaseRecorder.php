<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Models\InstallmentPlan;
use App\Models\Transaction;

/**
 * Records the FULL price at the start date so reports by category are right.
 * The row carries installment_plan_id, so the forecast and the card statement never count it as cash:
 * the money really leaves through the schedule rows.
 */
final class InstallmentPurchaseRecorder
{
    public function record(InstallmentPlan $plan, ?string $categoryId): Transaction
    {
        $isCard = $plan->funding_type === 'credit_card';

        $transaction = new Transaction();
        $transaction->fill([
            'type' => $isCard ? 'card_purchase' : 'expense',
            'txn_date' => $plan->start_date->format('Y-m-d'),
            'name' => $plan->name,
            'category_id' => $categoryId,
            'amount' => (string) $plan->price,
            'note' => 'ซื้อแบบผ่อนชำระ (ราคาเต็ม)',
            'payment_method' => $isCard ? 'credit_card' : 'transfer',
            'account_id' => $isCard ? null : $plan->account_id,
            'credit_card_id' => $isCard ? $plan->credit_card_id : null,
            'installment_plan_id' => $plan->id,
        ])->save();

        return $transaction;
    }
}