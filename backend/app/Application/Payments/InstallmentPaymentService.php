<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Installments\ScheduleItemMapper;
use App\Domain\Finance\Installments\PayoffCalculator;
use App\Domain\Finance\Installments\PayoffQuote;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Payments of plans paid from an ACCOUNT. (Card plans are billed through the card statements.)
 * A payoff uses the lender's real figures: discount and early fee come from the user, default 0.
 */
final class InstallmentPaymentService
{
    public function quote(InstallmentPlan $plan, string $interestDiscount = '0', string $earlyFee = '0'): PayoffQuote
    {
        $this->assertPayable($plan);

        try {
            return (new PayoffCalculator())->quote($this->pendingItems($plan), $interestDiscount, $earlyFee);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['interest_discount' => [$e->getMessage()]]);
        }
    }

    /** Pays ONE installment in full (also allowed before its due date). */
    public function payRow(InstallmentPlan $plan, string $itemId, string $date): InstallmentPayment
    {
        $this->assertPayable($plan);

        return DB::transaction(function () use ($plan, $itemId, $date): InstallmentPayment {
            $row = InstallmentScheduleItem::query()
                ->where('plan_id', $plan->id)->whereKey($itemId)->lockForUpdate()->first();

            if ($row === null || $row->status !== 'pending') {
                throw ValidationException::withMessages(['schedule_item_id' => ['ไม่พบงวดนี้ หรืองวดนี้ชำระ/ยกเลิกไปแล้ว']]);
            }

            $payment = new InstallmentPayment();
            $payment->fill([
                'plan_id' => $plan->id,
                'schedule_item_id' => $row->id,
                'payment_date' => $date,
                'amount' => (string) $row->total_due,
                'kind' => 'regular',
            ])->save();

            $row->status = 'paid';
            $row->paid_date = $date;
            $row->save();

            if (! InstallmentScheduleItem::query()->where('plan_id', $plan->id)->where('status', 'pending')->exists()) {
                $plan->status = 'completed';
                $plan->save();
            }

            return $payment;
        });
    }

    /** Closes the plan: one payment for everything still pending. */
    public function payoff(InstallmentPlan $plan, string $date, string $interestDiscount, string $earlyFee): InstallmentPayment
    {
        return DB::transaction(function () use ($plan, $date, $interestDiscount, $earlyFee): InstallmentPayment {
            $quote = $this->quote($plan, $interestDiscount, $earlyFee);

            $payment = new InstallmentPayment();
            $payment->fill([
                'plan_id' => $plan->id,
                'payment_date' => $date,
                'amount' => (string) $quote->payoffAmount,
                'kind' => 'payoff',
                'early_fee' => (string) $quote->earlyFee,
                'interest_discount' => (string) $quote->interestDiscount,
            ])->save();

            InstallmentScheduleItem::query()->where('plan_id', $plan->id)->where('status', 'pending')->get()
                ->each(function (InstallmentScheduleItem $row) use ($date): void {
                    $row->status = 'paid';
                    $row->paid_date = $date;
                    $row->save();
                });

            $plan->status = 'closed_early';
            $plan->save();

            return $payment;
        });
    }

    /** @return list<\App\Domain\Finance\Installments\ScheduleItem> */
    private function pendingItems(InstallmentPlan $plan): array
    {
        return InstallmentScheduleItem::query()
            ->where('plan_id', $plan->id)->where('status', 'pending')->orderBy('installment_no')->get()
            ->map(fn ($row) => ScheduleItemMapper::fromRow($row))->all();
    }

    private function assertPayable(InstallmentPlan $plan): void
    {
        if ($plan->funding_type !== 'account') {
            throw ValidationException::withMessages(['plan' => ['แผนที่ผ่อนผ่านบัตรเครดิตชำระผ่านบิลบัตร ไม่มีการชำระงวดแยก']]);
        }
        if ($plan->status !== 'active') {
            throw ValidationException::withMessages(['plan' => ['แผนนี้ไม่ได้อยู่ในสถานะใช้งาน']]);
        }
    }
}