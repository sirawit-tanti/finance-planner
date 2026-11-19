<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Application\Installments\ScheduleItemMapper;
use App\Domain\Finance\Forecast\CardCharge;
use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\GeneralExpenseEstimator;
use App\Domain\Finance\Forecast\InstallmentEventGenerator;
use App\Domain\Finance\Forecast\KnownStatement;
use App\Domain\Finance\Forecast\Period;
use App\Domain\Finance\Forecast\PeriodCalendar;
use App\Domain\Finance\Forecast\RecurrenceGenerator;
use App\Domain\Finance\Forecast\RecurringRule;
use App\Domain\Finance\Forecast\RuleOverride;
use App\Domain\Finance\Forecast\SavingsReserveGenerator;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Money\MoneyMath;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CreditCard;
use App\Models\CreditCardPayment;
use App\Models\CreditCardStatement;
use App\Models\IncomeSource;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\PlannedItem;
use App\Models\RecurringExpense;
use App\Models\RecurringOverride;
use App\Models\SavingsContribution;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Day;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Reads the logged-in user's data and turns it into a ForecastContext.
 * All user isolation comes from the models' global scope. No money is ever a float:
 * sums are done by PostgreSQL and returned as text.
 */
final class ForecastContextLoader
{
    public function defaultMonths(): int
    {
        return (int) (UserSetting::query()->value('forecast_default_months') ?? 12);
    }

    public function load(DateTimeImmutable $from, ?int $months = null): ForecastContext
    {
        // Read first: if data changes while we load, the snapshot is simply seen as stale next time.
        $dataVersion = (int) User::query()->whereKey(Auth::id())->value('data_version');

        $settings = UserSetting::query()->first();
        $months ??= (int) ($settings?->forecast_default_months ?? 12);
        $calendar = new PeriodCalendar((int) ($settings?->month_start_day ?? 1));
        $emergency = MoneyMath::exact2((string) ($settings?->emergency_fund_amount ?? '0'));

        $from = Day::of($from);
        $fromDay = $from->format('Y-m-d');
        $periods = $calendar->periods($from, $months);
        $to = $periods[count($periods) - 1]->end;

        $recurrence = new RecurrenceGenerator();
        $events = [];
        /** @var array<string, list<CardCharge>> $cardCharges card id => charges */
        $cardCharges = [];

        [$settledExpenses, $settledIncomes] = $this->settledOccurrences();
        $overrides = $this->overrides();

        // ---- recurring income ----
        foreach (IncomeSource::query()->where('is_active', true)->get() as $row) {
            $id = (string) $row->id;
            $rule = new RecurringRule(
                $id, $row->name, (string) $row->amount, Frequency::from($row->frequency),
                $row->pay_day, $row->weekday, Day::of($row->start_date),
                $row->end_date === null ? null : Day::of($row->end_date),
                $row->shift_rule, Direction::In, EventKind::IncomeRecurring, true,
                $overrides['income:' . $id] ?? [], $settledIncomes[$id] ?? [],
            );
            array_push($events, ...$recurrence->generate($rule, $from, $to));
        }

        // ---- recurring expenses (card-paid ones become card charges, not cash) ----
        foreach (RecurringExpense::query()->where('is_active', true)->get() as $row) {
            $id = (string) $row->id;
            $rule = new RecurringRule(
                $id, $row->name, (string) $row->amount, Frequency::from($row->frequency),
                $row->due_day, $row->weekday, Day::of($row->start_date),
                $row->end_date === null ? null : Day::of($row->end_date),
                $row->shift_rule, Direction::Out, EventKind::ExpenseRecurring, true,
                $overrides['expense:' . $id] ?? [], $settledExpenses[$id] ?? [],
            );
            $generated = $recurrence->generate($rule, $from, $to);

            if ($row->payment_method === 'credit_card') {
                foreach ($generated as $event) {
                    $cardCharges[(string) $row->credit_card_id][] = new CardCharge(
                        $event->date, $event->amount, $event->label, 'recurring_expense', $id
                    );
                }
            } else {
                array_push($events, ...$generated);
            }
        }

        // ---- one-off planned items (an overdue one is due now) ----
        foreach (PlannedItem::query()->where('status', 'planned')->get() as $item) {
            $date = Day::of($item->planned_date);
            if ($date->format('Y-m-d') < $fromDay) {
                $date = $from;
            }
            if ($date->format('Y-m-d') > $to->format('Y-m-d')) {
                continue;
            }
            $income = $item->kind === 'income';
            $events[] = new CashEvent(
                $date, (string) $item->amount,
                $income ? Direction::In : Direction::Out,
                $income ? EventKind::IncomePlanned : EventKind::ExpensePlanned,
                $item->name, 'planned_item', (string) $item->id,
            );
        }

        // ---- installment plans ----
        $installmentGenerator = new InstallmentEventGenerator();
        $plans = InstallmentPlan::query()->where('status', 'active')->get();
        $rowsByPlan = InstallmentScheduleItem::query()
            ->where('status', 'pending')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->orderBy('installment_no')
            ->get()
            ->groupBy('plan_id');

        foreach ($plans as $plan) {
            $rows = $rowsByPlan->get($plan->id, collect());

            if ($plan->funding_type === 'account') {
                $items = $rows->map(fn ($r) => ScheduleItemMapper::fromRow($r))->all();
                array_push($events, ...$installmentGenerator->accountEvents(
                    (string) $plan->id, $plan->name, $items, $from, $to
                ));
                continue;
            }

            // Card-funded: rows already billed to a statement are inside that statement.
            $items = $rows->filter(fn ($r) => $r->statement_id === null)
                ->map(fn ($r) => ScheduleItemMapper::fromRow($r, $from))->all();
            foreach ($installmentGenerator->cardCharges((string) $plan->id, $plan->name, $items) as $charge) {
                $cardCharges[(string) $plan->credit_card_id][] = $charge;
            }
        }

        // ---- cards ----
        // Issued statements (date up to the forecast start): only the latest counts, because older
        // balances were carried into it. Open statements (not issued yet): only the FRESH part counts
        // (total minus what was carried in), because the carried part is already forecast as the
        // earlier statement's own payment on its own due date.
        $cards = [];
        foreach (CreditCard::query()->where('status', 'active')->orderBy('name')->get() as $card) {
            $statements = CreditCardStatement::query()
                ->where('credit_card_id', $card->id)->orderBy('statement_date')->get();

            $latestIssuedId = null;
            foreach ($statements as $statement) {
                if ($statement->statement_date->format('Y-m-d') <= $fromDay) {
                    $latestIssuedId = $statement->id;
                }
            }

            $known = [];
            foreach ($statements as $statement) {
                $isOpen = $statement->statement_date->format('Y-m-d') > $fromDay;
                if (! $isOpen && $statement->id !== $latestIssuedId) {
                    continue;
                }

                $amount = MoneyMath::exact2((string) $statement->statement_amount);
                if ($isOpen) {
                    $amount = $amount->minus(MoneyMath::exact2((string) $statement->carried_over));
                }
                $outstanding = $amount->minus(MoneyMath::exact2((string) $statement->paid_amount));

                $known[] = new KnownStatement(
                    Day::of($statement->statement_date),
                    Day::of($statement->due_date),
                    $outstanding->isNegative() ? BigDecimal::of('0.00') : $outstanding,
                );
            }

            $cards[] = new CardContext(
                (string) $card->id, $card->name, (int) $card->statement_day, (int) $card->due_day,
                $known, $cardCharges[(string) $card->id] ?? [],
            );
        }

        // ---- everyday spending ----
        $current = $periods[0];
        $monthly = $this->sum(Budget::query()->whereNull('month'));
        if (! $monthly->isPositive()) {
            $monthly = $this->averageSpend($calendar, $current);
        }
        $spent = $this->spend($current->start->format('Y-m-d'), $fromDay);
        array_push($events, ...(new GeneralExpenseEstimator())->generate($calendar, $from, $months, $monthly, $spent));

        // ---- savings goals ----
        $reservedAtStart = BigDecimal::of('0.00');
        $savings = new SavingsReserveGenerator();
        foreach (SavingsGoal::query()->where('is_active', true)->get() as $goal) {
            $actual = SavingsContribution::query()->where('goal_id', $goal->id)->where('status', 'actual');
            $saved = $this->sum(clone $actual);
            $thisPeriod = $this->sum((clone $actual)->whereBetween(
                'contribution_date', [$current->start->format('Y-m-d'), $fromDay]
            ));
            $remaining = MoneyMath::exact2((string) $goal->target_amount)->minus($saved);
            $reservedAtStart = $reservedAtStart->plus($saved);

            array_push($events, ...$savings->generate(
                (string) $goal->id, $goal->name, (string) $goal->monthly_amount,
                $remaining->isPositive() ? $remaining : BigDecimal::of('0.00'),
                $calendar, $from, $months, $thisPeriod, Day::of($goal->start_date),
            ));
        }

        return new ForecastContext(
            $from, $to, $months, $calendar, $this->openingCash($fromDay), $emergency,
            $reservedAtStart, $events, $cards, $dataVersion,
        );
    }

    /** Cash across active accounts on $fromDay: opening balance plus real movements dated up to that day. */
    private function openingCash(string $fromDay): BigDecimal
    {
        $total = BigDecimal::of('0.00');
        $accounts = Account::query()->where('is_active', true)->whereDate('opening_date', '<=', $fromDay)->get();

        foreach ($accounts as $account) {
            $open = $account->opening_date->format('Y-m-d');
            $range = [$open, $fromDay];
            $planIds = InstallmentPlan::query()
                ->where('funding_type', 'account')->where('account_id', $account->id)->pluck('id');

            $transactions = fn (string $type) => $this->sum(
                Transaction::query()->where('account_id', $account->id)->where('type', $type)
                    ->whereNull('installment_plan_id')->whereBetween('txn_date', $range)
            );

            $total = $total
                ->plus(MoneyMath::exact2((string) $account->opening_balance))
                ->plus($transactions('income'))
                ->minus($transactions('expense'))
                ->minus($this->sum(
                    CreditCardPayment::query()->where('account_id', $account->id)->whereBetween('payment_date', $range)
                ))
                ->minus($this->sum(
                    InstallmentPayment::query()->whereIn('plan_id', $planIds)->whereBetween('payment_date', $range)
                ));
        }

        return $total;
    }

    /** @return array{0: array<string, array<string, true>>, 1: array<string, array<string, true>>} expenses, incomes by id */
    private function settledOccurrences(): array
    {
        $expenses = [];
        $incomes = [];

        $rows = Transaction::query()->whereNotNull('occurrence_date')
            ->get(['recurring_expense_id', 'income_source_id', 'occurrence_date']);

        foreach ($rows as $row) {
            $key = $row->occurrence_date->format('Y-m-d');
            if ($row->recurring_expense_id !== null) {
                $expenses[(string) $row->recurring_expense_id][$key] = true;
            }
            if ($row->income_source_id !== null) {
                $incomes[(string) $row->income_source_id][$key] = true;
            }
        }

        return [$expenses, $incomes];
    }

    /** @return array<string, array<string, RuleOverride>> keyed "income:<id>" / "expense:<id>", then occurrence date */
    private function overrides(): array
    {
        $map = [];
        foreach (RecurringOverride::query()->get() as $row) {
            $map[$row->source_type . ':' . $row->source_id][$row->occurrence_date->format('Y-m-d')] = new RuleOverride(
                $row->override_amount,
                $row->override_date === null ? null : Day::of($row->override_date),
                (bool) $row->is_skipped,
            );
        }

        return $map;
    }

    /** Spending that is not a recurring bill or an installment purchase (those are forecast separately). */
    private function spend(string $start, string $end): BigDecimal
    {
        return $this->sum(
            Transaction::query()
                ->whereIn('type', ['expense', 'card_purchase'])
                ->whereNull('recurring_expense_id')
                ->whereNull('installment_plan_id')
                ->whereBetween('txn_date', [$start, $end])
        );
    }

    /** Average of the 3 periods before the current one. A period with no spending counts as "no data". */
    private function averageSpend(PeriodCalendar $calendar, Period $current): BigDecimal
    {
        $totals = [];
        $cursor = $current->start;

        for ($i = 0; $i < 3; $i++) {
            $period = $calendar->containing($cursor->modify('-1 day'));
            $total = $this->spend($period->start->format('Y-m-d'), $period->end->format('Y-m-d'));
            if ($total->isPositive()) {
                $totals[] = $total;
            }
            $cursor = $period->start;
        }

        return GeneralExpenseEstimator::fallbackAverage($totals);
    }

    /** SUM done by PostgreSQL and returned as text: money never passes through a float. */
    private function sum(Builder $query, string $column = 'amount'): BigDecimal
    {
        $total = $query->toBase()->selectRaw("COALESCE(SUM({$column}), 0)::text AS total")->value('total');

        return MoneyMath::exact2((string) $total);
    }
}