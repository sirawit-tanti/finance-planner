<?php

declare(strict_types=1);

namespace App\Domain\Finance\Savings;

use App\Domain\Finance\Dates\DateRules;
use App\Domain\Finance\Forecast\ForecastResult;
use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final class SavingsPlanner
{
    /** Progress toward a goal, 0.00 - 100.00 (capped), half-up. */
    public function progressPercent(BigDecimal|string $target, BigDecimal|string $saved): BigDecimal
    {
        $target = MoneyMath::exact2($target);
        $saved = MoneyMath::exact2($saved);
        if (! $target->isPositive() || $saved->isNegative()) {
            throw new InvalidArgumentException('Target must be positive and saved must not be negative.');
        }

        $percent = MoneyMath::round2(MoneyMath::div($saved->multipliedBy(100), $target));

        return $percent->isGreaterThan(100) ? BigDecimal::of('100.00') : $percent;
    }

    /**
     * Whole months still needed at the monthly amount: ceil(missing / monthly).
     * 0 = already reached, null = never (monthly is 0).
     */
    public function monthsToGoal(BigDecimal|string $target, BigDecimal|string $saved, BigDecimal|string $monthly): ?int
    {
        $missing = MoneyMath::exact2($target)->minus(MoneyMath::exact2($saved));
        $monthly = MoneyMath::exact2($monthly);

        if (! $missing->isPositive()) {
            return 0;
        }
        if (! $monthly->isPositive()) {
            return null;
        }

        return MoneyMath::ceilDiv($missing, $monthly);
    }

    /** Expected date of reaching the goal, keeping the day of month of $asOf (month-end clamped). */
    public function estimatedDate(DateTimeImmutable $asOf, int $months): DateTimeImmutable
    {
        return DateRules::addMonthsClamped($asOf, $months);
    }

    /**
     * Suggested monthly saving from the REAL forecast (never a number picked in isolation):
     * the smallest monthly surplus (money in - money out) over the COMPLETE months,
     * rounded down to a multiple of 100 baht, never below 0.
     * The partial first month is ignored because it has fewer pay days.
     * Run the forecast WITHOUT savings reserves first (reserves do not change cash, so this holds either way).
     */
    public function recommendedMonthly(ForecastResult $forecast): BigDecimal
    {
        $smallest = null;
        foreach ($forecast->months as $month) {
            if ($month->isPartial) {
                continue;
            }
            $surplus = $month->totalIn->minus($month->totalOut);
            if ($smallest === null || $surplus->isLessThan($smallest)) {
                $smallest = $surplus;
            }
        }

        if ($smallest === null || ! $smallest->isPositive()) {
            return BigDecimal::of('0.00');
        }

        return MoneyMath::floorToMultiple($smallest, 100);
    }
}