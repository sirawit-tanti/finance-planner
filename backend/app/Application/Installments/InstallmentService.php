<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Application\Statements\StatementRecalculator;
use App\Application\Statements\StatementService;
use App\Domain\Finance\Installments\Schedule;
use App\Domain\Finance\Installments\ScheduleBuilder;
use App\Models\CreditCard;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Support\Day;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InstallmentService
{
    public function __construct(
        private readonly InstallmentPurchaseRecorder $recorder,
        private readonly StatementService $statements,
        private readonly StatementRecalculator $recalculator,
    ) {
    }

    public function schedule(InstallmentInput $input): Schedule
    {
        try {
            return (new ScheduleBuilder())->build($input->terms);
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['terms' => [$e->getMessage()]]);
        }
    }

    /**
     * Creates the plan, its whole schedule and the full-price purchase record in ONE transaction.
     * For a card plan the card's statements are brought up to date so rows already charged are billed.
     */
    public function create(InstallmentInput $input, ?string $categoryId = null): InstallmentPlan
    {
        $schedule = $this->schedule($input);
        $t = $input->terms;

        return DB::transaction(function () use ($input, $schedule, $t, $categoryId): InstallmentPlan {
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

            $this->recorder->record($plan, $categoryId);

            if ($input->fundingType === 'credit_card') {
                $card = CreditCard::query()->findOrFail($input->cardId);
                $today = Day::today();
                if ($plan->first_due_date->format('Y-m-d') <= $today->format('Y-m-d')) {
                    $this->statements->statementFor($card, Day::of($plan->first_due_date));
                }
                $this->statements->statementFor($card, $today);
            }

            return $plan->load('scheduleItems');
        });
    }

    /** Cancels the plan and every row still pending. Paid rows are kept as history. */
    public function cancel(InstallmentPlan $plan): InstallmentPlan
    {
        if (! in_array($plan->status, ['active'], true)) {
            throw ValidationException::withMessages(['plan' => ['ยกเลิกได้เฉพาะแผนที่ยังใช้งานอยู่']]);
        }

        return DB::transaction(function () use ($plan): InstallmentPlan {
            InstallmentScheduleItem::query()->where('plan_id', $plan->id)->where('status', 'pending')->get()
                ->each(function (InstallmentScheduleItem $row): void {
                    $row->status = 'cancelled';
                    $row->save();
                });

            $plan->status = 'cancelled';
            $plan->save();

            if ($plan->funding_type === 'credit_card') {
                $this->recalculator->recalculateCard(CreditCard::query()->findOrFail($plan->credit_card_id));
            }

            return $plan->load('scheduleItems');
        });
    }
}