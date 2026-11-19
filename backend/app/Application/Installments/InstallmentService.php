<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Domain\Finance\Installments\Schedule;
use App\Domain\Finance\Installments\ScheduleBuilder;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InstallmentService
{
    public function schedule(InstallmentInput $input): Schedule
    {
        try {
            return (new ScheduleBuilder())->build($input->terms);
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['terms' => [$e->getMessage()]]);
        }
    }

    /** Creates the plan and its whole schedule in ONE transaction (all rows or none). */
    public function create(InstallmentInput $input): InstallmentPlan
    {
        $schedule = $this->schedule($input);
        $t = $input->terms;

        return DB::transaction(function () use ($input, $schedule, $t): InstallmentPlan {
            $plan = new InstallmentPlan();
            $plan->fill([
                'name' => $input->name,
                'note' => $input->note,
                'interest_method' => $t->method->value,
                'annual_rate_percent' => (string) $t->annualRatePercent,
                'price' => (string) $input->price,
                'down_payment' => (string) $input->downPayment,
                'financed_amount' => (string) $t->financedAmount,
                'term_count' => $t->termCount,
                'frequency' => $t->frequency->value,
                'start_date' => $t->startDate->format('Y-m-d'),
                'first_due_date' => $t->firstDueDate->format('Y-m-d'),
                'fee_amount' => (string) $t->feeAmount,
                'fee_mode' => $t->feeMode->value,
                'funding_type' => $input->fundingType,
                'credit_card_id' => $input->cardId,
                'account_id' => $input->accountId,
                'total_interest' => (string) $schedule->totalInterest(),
                'total_payable' => (string) $schedule->totalPayable(),
                'status' => 'active',
            ])->save();

            foreach ($schedule->items as $item) {
                (new InstallmentScheduleItem())->fill([
                    'plan_id' => $plan->id,
                    'installment_no' => $item->installmentNo,
                    'due_date' => $item->dueDate->format('Y-m-d'),
                    'principal_part' => (string) $item->principalPart,
                    'interest_part' => (string) $item->interestPart,
                    'fee_part' => (string) $item->feePart,
                    'total_due' => (string) $item->totalDue,
                    'remaining_principal_after' => (string) $item->remainingPrincipalAfter,
                    'status' => 'pending',
                ])->save();
            }

            return $plan->load('scheduleItems');
        });
    }
}