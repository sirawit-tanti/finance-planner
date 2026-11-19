<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Walks the events day by day and summarises each financial month.
 * Pure function: same input => same output. No DB, no framework, no float.
 *
 *  - events dated before $from are ignored (already reflected in $openingCash)
 *  - events after the last period are ignored
 *  - same day: money in first, then out, then reserve
 *  - Reserve events do not change cash; they only reduce freeCash
 */
final class ForecastEngine
{
    public const MAX_MONTHS = 36;

    /** @param list<CashEvent> $events */
    public function run(
        DateTimeImmutable $from,
        int $months,
        PeriodCalendar $calendar,
        BigDecimal|string $openingCash,
        array $events,
        BigDecimal|string $emergencyFund = '0',
        BigDecimal|string $reservedAtStart = '0',
    ): ForecastResult {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('Months must be between 1 and ' . self::MAX_MONTHS . '.');
        }

        $balance = MoneyMath::exact2($openingCash);
        $emergency = MoneyMath::exact2($emergencyFund);
        $reserved = MoneyMath::exact2($reservedAtStart);
        if ($emergency->isNegative() || $reserved->isNegative()) {
            throw new InvalidArgumentException('Emergency fund and reserved savings must not be negative.');
        }

        $periods = $calendar->periods($from, $months);
        $fromDay = $from->format('Y-m-d');

        $buckets = array_fill(0, count($periods), []);
        foreach ($events as $event) {
            $day = $event->date->format('Y-m-d');
            if ($day < $fromDay) {
                continue;
            }
            foreach ($periods as $i => $period) {
                if ($day >= $period->start->format('Y-m-d') && $day <= $period->end->format('Y-m-d')) {
                    $buckets[$i][] = $event;
                    break;
                }
            }
        }

        $result = [];
        foreach ($periods as $i => $period) {
            usort($buckets[$i], static fn (CashEvent $a, CashEvent $b): int => [
                $a->date->format('Y-m-d'), $a->direction->order(), $a->label,
            ] <=> [
                $b->date->format('Y-m-d'), $b->direction->order(), $b->label,
            ]);

            $month = $this->month($period, $from, $balance, $reserved, $emergency, $buckets[$i]);
            $balance = $month->closingCash;
            $reserved = $month->reservedCumulative;
            $result[] = $month;
        }

        return new ForecastResult($result);
    }

    /** @param list<CashEvent> $events already sorted */
    private function month(
        Period $period,
        DateTimeImmutable $from,
        BigDecimal $opening,
        BigDecimal $reservedBefore,
        BigDecimal $emergency,
        array $events,
    ): MonthForecast {
        $zero = BigDecimal::of('0.00');
        $isPartial = $from->format('Y-m-d') > $period->start->format('Y-m-d');
        $effectiveStart = $isPartial ? $from : $period->start;

        $balance = $opening;
        $min = $opening;
        $minDate = $effectiveStart;
        $totalIn = $zero;
        $totalOut = $zero;
        $saved = $zero;
        $byKind = [];
        $principal = $zero;
        $interest = $zero;
        $fees = $zero;

        foreach ($events as $event) {
            $byKind[$event->kind->value] = ($byKind[$event->kind->value] ?? $zero)->plus($event->amount);

            if ($event->direction === Direction::Reserve) {
                $saved = $saved->plus($event->amount);
                continue;
            }

            if ($event->direction === Direction::In) {
                $balance = $balance->plus($event->amount);
                $totalIn = $totalIn->plus($event->amount);
            } else {
                $balance = $balance->minus($event->amount);
                $totalOut = $totalOut->plus($event->amount);
            }

            if ($balance->isLessThan($min)) {
                $min = $balance;
                $minDate = $event->date;
            }

            if ($event->kind === EventKind::Installment) {
                if ($event->principalPart === null && $event->interestPart === null && $event->feePart === null) {
                    $principal = $principal->plus($event->amount); // no breakdown known: all principal
                } else {
                    $principal = $principal->plus($event->principalPart ?? $zero);
                    $interest = $interest->plus($event->interestPart ?? $zero);
                    $fees = $fees->plus($event->feePart ?? $zero);
                }
            }
        }

        $kind = static fn (EventKind $k): BigDecimal => $byKind[$k->value] ?? $zero;
        $reservedCumulative = $reservedBefore->plus($saved);

        $risk = match (true) {
            $min->isNegative() => MonthForecast::RISK_NEGATIVE,
            $emergency->isPositive() && $min->isLessThan($emergency) => MonthForecast::RISK_LOW,
            default => MonthForecast::RISK_OK,
        };

        return new MonthForecast(
            key: $period->key,
            start: $period->start,
            end: $period->end,
            isPartial: $isPartial,
            openingCash: $opening,
            incomeRecurring: $kind(EventKind::IncomeRecurring),
            incomePlanned: $kind(EventKind::IncomePlanned),
            expenseRecurring: $kind(EventKind::ExpenseRecurring),
            expensePlanned: $kind(EventKind::ExpensePlanned),
            expenseGeneral: $kind(EventKind::ExpenseGeneral),
            cardPayments: $kind(EventKind::CardPayment),
            installmentsTotal: $kind(EventKind::Installment),
            installmentPrincipal: $principal,
            installmentInterest: $interest,
            installmentFees: $fees,
            savingsReserved: $saved,
            totalIn: $totalIn,
            totalOut: $totalOut,
            closingCash: $balance,
            minBalance: $min,
            minBalanceDate: $minDate,
            reservedCumulative: $reservedCumulative,
            freeCash: $balance->minus($reservedCumulative)->minus($emergency),
            risk: $risk,
            events: $events,
        );
    }
}