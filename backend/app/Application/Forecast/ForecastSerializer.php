<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\ForecastResult;
use App\Domain\Finance\Forecast\MonthForecast;
use App\Domain\Finance\Savings\SavingsPlanner;
use App\Domain\Finance\WhatIf\ScenarioComparison;
use Brick\Math\BigDecimal;

/** Domain objects => JSON-ready arrays. Money is always a string. */
final class ForecastSerializer
{
    /** @return array<string, mixed> */
    public function forecast(ForecastContext $ctx, ForecastResult $result): array
    {
        return [
            'from' => $ctx->from->format('Y-m-d'),
            'to' => $ctx->to->format('Y-m-d'),
            'horizon_months' => $ctx->months,
            'generated_at' => now()->toIso8601String(),
            'data_version' => $ctx->dataVersion,
            'opening_cash' => (string) $ctx->openingCash,
            'emergency_fund' => (string) $ctx->emergencyFund,
            'summary' => $this->summary($result) + [
                'recommended_monthly_saving' => (string) (new SavingsPlanner())->recommendedMonthly($result),
            ],
            'months' => array_map(fn (MonthForecast $m) => $this->month($m), $result->months),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(ForecastResult $result): array
    {
        $closingAfter = [];
        foreach ([1, 3, 6, 12, 24, 36] as $n) {
            if ($n <= count($result->months)) {
                $closingAfter[(string) $n] = (string) $result->closingAfter($n);
            }
        }

        return [
            'final_closing' => (string) $result->finalClosing(),
            'lowest_balance' => (string) $result->lowestBalance(),
            'risk_months' => array_map(fn (MonthForecast $m) => $m->key, $result->riskMonths()),
            'total_installments' => (string) $result->totalInstallments(),
            'total_interest' => (string) $result->totalInterest(),
            'closing_after' => $closingAfter,
        ];
    }

    /** @return array<string, mixed> */
    public function comparison(ScenarioComparison $c): array
    {
        return [
            'final_closing_delta' => (string) $c->finalClosingDelta,
            'lowest_balance_before' => (string) $c->lowestBalanceBefore,
            'lowest_balance_after' => (string) $c->lowestBalanceAfter,
            'extra_debt_service' => (string) $c->extraDebtService,
            'extra_interest' => (string) $c->extraInterest,
            'savings_reserved_delta' => (string) $c->savingsReservedDelta,
            'new_risk_months' => $c->newRiskMonths,
            'resolved_risk_months' => $c->resolvedRiskMonths,
            'months' => array_map(fn ($m) => [
                'key' => $m->key,
                'closing_before' => (string) $m->closingBefore,
                'closing_after' => (string) $m->closingAfter,
                'closing_delta' => (string) $m->closingDelta,
                'min_before' => (string) $m->minBefore,
                'min_after' => (string) $m->minAfter,
                'risk_before' => $m->riskBefore,
                'risk_after' => $m->riskAfter,
            ], $c->months),
        ];
    }

    /** @return array<string, mixed> */
    private function month(MonthForecast $m): array
    {
        return [
            'key' => $m->key,
            'start' => $m->start->format('Y-m-d'),
            'end' => $m->end->format('Y-m-d'),
            'is_partial' => $m->isPartial,
            'opening_cash' => (string) $m->openingCash,
            'income_recurring' => (string) $m->incomeRecurring,
            'income_planned' => (string) $m->incomePlanned,
            'expense_recurring' => (string) $m->expenseRecurring,
            'expense_planned' => (string) $m->expensePlanned,
            'expense_general' => (string) $m->expenseGeneral,
            'card_payments' => (string) $m->cardPayments,
            'installments_total' => (string) $m->installmentsTotal,
            'installment_principal' => (string) $m->installmentPrincipal,
            'installment_interest' => (string) $m->installmentInterest,
            'installment_fees' => (string) $m->installmentFees,
            'savings_reserved' => (string) $m->savingsReserved,
            'total_in' => (string) $m->totalIn,
            'total_out' => (string) $m->totalOut,
            'closing_cash' => (string) $m->closingCash,
            'min_balance' => (string) $m->minBalance,
            'min_balance_date' => $m->minBalanceDate->format('Y-m-d'),
            'reserved_cumulative' => (string) $m->reservedCumulative,
            'free_cash' => (string) $m->freeCash,
            'risk' => $m->risk,
            'events' => $this->events($m->events),
        ];
    }

    /**
     * Drill-down rows. The daily general-spending estimates are merged into ONE row
     * so a month does not list 30 near-identical lines.
     *
     * @param list<CashEvent> $events
     * @return list<array<string, mixed>>
     */
    private function events(array $events): array
    {
        $rows = [];
        $generalTotal = null;
        $generalDate = null;
        $generalDays = 0;

        foreach ($events as $e) {
            if ($e->kind === EventKind::ExpenseGeneral) {
                $generalTotal = ($generalTotal ?? BigDecimal::of('0.00'))->plus($e->amount);
                $generalDate ??= $e->date;
                $generalDays++;
                continue;
            }

            $rows[] = [
                'date' => $e->date->format('Y-m-d'),
                'label' => $e->label,
                'kind' => $e->kind->value,
                'direction' => $e->direction->value,
                'amount' => (string) $e->amount,
                'source_type' => $e->sourceType,
                'source_id' => $e->sourceId,
                'principal' => $e->principalPart === null ? null : (string) $e->principalPart,
                'interest' => $e->interestPart === null ? null : (string) $e->interestPart,
                'fee' => $e->feePart === null ? null : (string) $e->feePart,
            ];
        }

        if ($generalTotal !== null) {
            $rows[] = [
                'date' => $generalDate->format('Y-m-d'),
                'label' => "ค่าใช้จ่ายทั่วไป (ประมาณการ {$generalDays} วัน)",
                'kind' => EventKind::ExpenseGeneral->value,
                'direction' => 'out',
                'amount' => (string) $generalTotal,
                'source_type' => 'general_estimate',
                'source_id' => null,
                'principal' => null,
                'interest' => null,
                'fee' => null,
            ];
        }

        return $rows;
    }
}