<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Savings set aside each month for one goal (Direction::Reserve: cash is unchanged,
 * but the money is no longer "free"). Stops once the goal is reached.
 *  - current month: monthly - already contributed this month
 *  - never reserves more than the amount still missing to reach the target
 */
final class SavingsReserveGenerator
{
    /** @return list<CashEvent> */
    public function generate(
        string $goalId,
        string $name,
        BigDecimal|string $monthlyAmount,
        BigDecimal|string $remainingToTarget,
        PeriodCalendar $calendar,
        DateTimeImmutable $from,
        int $months,
        BigDecimal|string $contributedThisPeriod = '0',
        ?DateTimeImmutable $startDate = null,
    ): array {
        $monthly = MoneyMath::exact2($monthlyAmount);
        $remaining = MoneyMath::exact2($remainingToTarget);
        $contributed = MoneyMath::exact2($contributedThisPeriod);
        if ($monthly->isNegative() || $contributed->isNegative()) {
            throw new InvalidArgumentException('Amounts must not be negative.');
        }

        $fromDay = $from->format('Y-m-d');
        $events = [];

        foreach ($calendar->periods($from, $months) as $index => $period) {
            if (! $remaining->isPositive()) {
                break;
            }
            if ($startDate !== null && $period->end->format('Y-m-d') < $startDate->format('Y-m-d')) {
                continue;
            }

            $amount = $index === 0 ? $monthly->minus($contributed) : $monthly;
            if ($amount->isGreaterThan($remaining)) {
                $amount = $remaining;
            }
            if (! $amount->isPositive()) {
                continue;
            }

            $date = ($index === 0 && $fromDay > $period->start->format('Y-m-d')) ? $from : $period->start;
            $events[] = new CashEvent(
                $date, $amount, Direction::Reserve, EventKind::SavingsReserve,
                'ออม: ' . $name, 'savings_goal', $goalId
            );
            $remaining = $remaining->minus($amount);
        }

        return $events;
    }
}