<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Everyday spending that has not happened yet (food, travel...), as one small event per day.
 *  - future months: the monthly amount spread evenly over every day of the month
 *  - current month: max(0, monthly - already spent) spread over the days left (from $from)
 * The monthly amount is the user's budget, or the 3-month average when there is no budget
 * (see fallbackAverage). Treated as cash leaving directly (agreed in Phase 2).
 */
final class GeneralExpenseEstimator
{
    /** @return list<CashEvent> */
    public function generate(
        PeriodCalendar $calendar,
        DateTimeImmutable $from,
        int $months,
        BigDecimal|string $monthlyAmount,
        BigDecimal|string $spentSoFarThisPeriod = '0',
    ): array {
        $monthly = MoneyMath::exact2($monthlyAmount);
        $spent = MoneyMath::exact2($spentSoFarThisPeriod);
        if ($monthly->isNegative() || $spent->isNegative()) {
            throw new InvalidArgumentException('Monthly amount and spent amount must not be negative.');
        }

        $fromDay = $from->format('Y-m-d');
        $events = [];

        foreach ($calendar->periods($from, $months) as $index => $period) {
            $first = $index === 0;
            $start = ($first && $fromDay > $period->start->format('Y-m-d'))
                ? new DateTimeImmutable($fromDay, new \DateTimeZone('UTC'))
                : $period->start;

            $budget = $monthly;
            if ($first) {
                $left = $monthly->minus($spent);
                $budget = $left->isPositive() ? $left : BigDecimal::of('0.00');
            }
            if (! $budget->isPositive()) {
                continue;
            }

            $days = (int) $start->diff($period->end)->days + 1;
            $amounts = MoneyMath::splitEvenly($budget, $days);

            foreach ($amounts as $i => $amount) {
                if ($amount->isPositive()) {
                    $events[] = new CashEvent(
                        $start->modify("+{$i} days"),
                        $amount,
                        Direction::Out,
                        EventKind::ExpenseGeneral,
                        'ค่าใช้จ่ายทั่วไป (ประมาณการ)',
                        'general_estimate',
                        null,
                    );
                }
            }
        }

        return $events;
    }

    /**
     * Average of the last few months' real spending (half-up, 2 decimals).
     * Used when the user has no budget. No history => 0.00.
     *
     * @param list<BigDecimal|string> $monthlyTotals
     */
    public static function fallbackAverage(array $monthlyTotals): BigDecimal
    {
        if ($monthlyTotals === []) {
            return BigDecimal::of('0.00');
        }

        $sum = BigDecimal::of('0.00');
        foreach ($monthlyTotals as $total) {
            $sum = $sum->plus(MoneyMath::exact2($total));
        }

        return MoneyMath::round2(MoneyMath::div($sum, count($monthlyTotals)));
    }
}