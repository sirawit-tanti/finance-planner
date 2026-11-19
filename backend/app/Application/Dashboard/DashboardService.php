<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Forecast\ForecastContextLoader;
use App\Application\Forecast\ForecastRunner;
use App\Application\Forecast\ForecastSerializer;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Money\MoneyMath;
use App\Domain\Finance\Savings\SavingsPlanner;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\SavingsContribution;
use App\Models\SavingsGoal;
use App\Support\Sql;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Everything the home screen shows, built from the same forecast the Forecast page uses.
 *
 *  free_to_spend = cash now
 *                - bills due before the next salary arrives
 *                - savings planned this period
 *                - emergency fund
 * (can be negative: that is exactly the warning the user needs)
 */
final class DashboardService
{
    public function __construct(
        private readonly ForecastContextLoader $loader,
        private readonly ForecastRunner $runner,
        private readonly ForecastSerializer $serializer,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(DateTimeImmutable $today): array
    {
        $context = $this->loader->load($today, 12);
        $result = $this->runner->run($context);
        $month = $result->months[0];
        $zero = BigDecimal::of('0.00');

        $nextIncome = null;
        foreach ($result->months as $m) {
            foreach ($m->events as $event) {
                if ($event->direction === Direction::In && $event->kind === EventKind::IncomeRecurring) {
                    $nextIncome = $event->date;
                    break 2;
                }
            }
        }
        $windowEnd = $nextIncome ?? $month->end->modify('+1 day');
        $endKey = $windowEnd->format('Y-m-d');

        $obligations = $zero;
        foreach ($result->months as $m) {
            foreach ($m->events as $event) {
                if ($event->direction === Direction::Out && $event->date->format('Y-m-d') < $endKey) {
                    $obligations = $obligations->plus($event->amount);
                }
            }
        }

        $free = $context->openingCash->minus($obligations)->minus($month->savingsReserved)->minus($context->emergencyFund);
        $summary = $this->serializer->summary($result);

        return [
            'as_of' => $today->format('Y-m-d'),
            'cash_now' => (string) $context->openingCash,
            'emergency_fund' => (string) $context->emergencyFund,
            'month' => [
                'key' => $month->key,
                'start' => $month->start->format('Y-m-d'),
                'end' => $month->end->format('Y-m-d'),
                'is_partial' => $month->isPartial,
                'income' => (string) $month->incomeRecurring->plus($month->incomePlanned),
                'expense_recurring' => (string) $month->expenseRecurring,
                'expense_general' => (string) $month->expenseGeneral,
                'card_payments' => (string) $month->cardPayments,
                'installments' => (string) $month->installmentsTotal,
                'savings_reserved' => (string) $month->savingsReserved,
                'total_in' => (string) $month->totalIn,
                'total_out' => (string) $month->totalOut,
                'closing_cash' => (string) $month->closingCash,
                'min_balance' => (string) $month->minBalance,
                'risk' => $month->risk,
            ],
            'obligations_before_next_income' => [
                'amount' => (string) $obligations,
                'until' => $endKey,
                'next_income_date' => $nextIncome?->format('Y-m-d'),
            ],
            'free_to_spend' => (string) $free,
            'cards' => $this->cards($today),
            'installments' => $this->installments(),
            'savings' => [
                'recommended_monthly' => (string) (new SavingsPlanner())->recommendedMonthly($result),
                'goals' => $this->goals(),
            ],
            'forecast' => [
                'closing_after' => $summary['closing_after'],
                'lowest_balance' => $summary['lowest_balance'],
                'risk_months' => $summary['risk_months'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cards(DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');
        $zero = BigDecimal::of('0.00');
        $out = [];

        foreach (CreditCard::query()->where('status', 'active')->orderBy('name')->get() as $card) {
            $issued = null;
            $open = [];
            foreach (CreditCardStatement::query()->where('credit_card_id', $card->id)->orderBy('statement_date')->get() as $s) {
                if ($s->statement_date->format('Y-m-d') <= $todayKey) {
                    $issued = $s; // the latest issued one: older balances are carried into it
                } else {
                    $open[] = $s;
                }
            }

            $issuedData = null;
            $issuedOutstanding = $zero;
            if ($issued !== null) {
                $issuedOutstanding = MoneyMath::exact2((string) $issued->statement_amount)
                    ->minus(MoneyMath::exact2((string) $issued->paid_amount));
                $issuedOutstanding = $issuedOutstanding->isNegative() ? $zero : $issuedOutstanding;
                $issuedData = [
                    'statement_id' => $issued->id,
                    'statement_date' => $issued->statement_date->format('Y-m-d'),
                    'due_date' => $issued->due_date->format('Y-m-d'),
                    'statement_amount' => (string) $issued->statement_amount,
                    'outstanding' => (string) $issuedOutstanding,
                    'min_payment' => (string) $issued->min_payment,
                    'status' => $issued->status,
                    'is_overdue' => $issuedOutstanding->isPositive() && $todayKey > $issued->due_date->format('Y-m-d'),
                ];
            }

            $fresh = $zero;
            $openDue = null;
            foreach ($open as $s) {
                $part = MoneyMath::exact2((string) $s->statement_amount)
                    ->minus(MoneyMath::exact2((string) $s->carried_over))
                    ->minus(MoneyMath::exact2((string) $s->paid_amount));
                $fresh = $fresh->plus($part->isNegative() ? $zero : $part);
                $openDue ??= $s->due_date->format('Y-m-d');
            }

            $totalOwed = $issuedOutstanding->plus($fresh);
            $limit = MoneyMath::exact2((string) $card->credit_limit);
            $available = $limit->minus($totalOwed);

            $out[] = [
                'id' => $card->id,
                'name' => $card->name,
                'bank' => $card->bank,
                'credit_limit' => (string) $limit,
                'issued' => $issuedData,
                'open' => $open === [] ? null : ['fresh_amount' => (string) $fresh, 'due_date' => $openDue],
                'total_owed' => (string) $totalOwed,
                'available_credit' => $limit->isPositive() ? (string) ($available->isNegative() ? $zero : $available) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function installments(): array
    {
        $plans = InstallmentPlan::query()->where('status', 'active')->orderBy('name')->get();
        $rows = InstallmentScheduleItem::query()
            ->where('status', 'pending')->whereIn('plan_id', $plans->pluck('id'))->orderBy('installment_no')->get()
            ->groupBy('plan_id');

        $zero = BigDecimal::of('0.00');
        $items = [];
        $totalRemaining = $zero;
        $lastDue = null;

        foreach ($plans as $plan) {
            $pending = $rows->get($plan->id, collect());
            if ($pending->isEmpty()) {
                continue;
            }

            $principal = $interest = $total = $zero;
            foreach ($pending as $row) {
                $principal = $principal->plus(MoneyMath::exact2((string) $row->principal_part));
                $interest = $interest->plus(MoneyMath::exact2((string) $row->interest_part));
                $total = $total->plus(MoneyMath::exact2((string) $row->total_due));
            }

            $next = $pending->sortBy(fn ($r) => $r->due_date->format('Y-m-d'))->first();
            $last = $pending->max(fn ($r) => $r->due_date->format('Y-m-d'));
            $lastDue = $lastDue === null || $last > $lastDue ? $last : $lastDue;
            $totalRemaining = $totalRemaining->plus($total);

            $items[] = [
                'id' => $plan->id,
                'name' => $plan->name,
                'funding_type' => $plan->funding_type,
                'remaining_installments' => $pending->where('installment_no', '>=', 1)->count(),
                'remaining_principal' => (string) $principal,
                'remaining_interest' => (string) $interest,
                'remaining_total' => (string) $total,
                'next_due_date' => $next->due_date->format('Y-m-d'),
                'next_amount' => (string) $next->total_due,
            ];
        }

        return [
            'active_count' => count($items),
            'remaining_total' => (string) $totalRemaining,
            'debt_free_date' => $lastDue,
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function goals(): array
    {
        $planner = new SavingsPlanner();
        $out = [];

        foreach (SavingsGoal::query()->where('is_active', true)->orderBy('name')->get() as $goal) {
            $saved = Sql::sum(SavingsContribution::query()->where('goal_id', $goal->id)->where('status', 'actual'));
            $out[] = [
                'id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => (string) $goal->target_amount,
                'saved_amount' => (string) $saved,
                'progress_percent' => (string) $planner->progressPercent((string) $goal->target_amount, $saved),
                'months_to_goal' => $planner->monthsToGoal((string) $goal->target_amount, $saved, (string) $goal->monthly_amount),
            ];
        }

        return $out;
    }
}