<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A recurring income or expense, as stored in income_sources / recurring_expenses.
 * overrides and settled are keyed by the ORIGINAL occurrence date (Y-m-d), before any shift.
 */
final class RecurringRule
{
    public readonly BigDecimal $amount;

    /**
     * @param array<string, RuleOverride> $overrides
     * @param array<string, true>         $settled   occurrences already paid/received (never forecast again)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        BigDecimal|string $amount,
        public readonly Frequency $frequency,
        public readonly ?int $dayOfMonth,
        public readonly ?int $weekday,
        public readonly DateTimeImmutable $startDate,
        public readonly ?DateTimeImmutable $endDate,
        public readonly string $shiftRule,
        public readonly Direction $direction,
        public readonly EventKind $kind,
        public readonly bool $isActive = true,
        public readonly array $overrides = [],
        public readonly array $settled = [],
    ) {
        $this->amount = MoneyMath::exact2($amount);

        if ($this->amount->isNegative()) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }
        if ($direction === Direction::Reserve) {
            throw new InvalidArgumentException('A recurring rule is either money in or money out.');
        }

        $monthly = in_array($frequency, [Frequency::Monthly, Frequency::Yearly], true);
        if ($monthly && ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31)) {
            throw new InvalidArgumentException('Monthly and yearly rules need a day of month (1-31).');
        }
        if (! $monthly && ($weekday === null || $weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('Weekly and biweekly rules need a weekday (1 = Monday ... 7 = Sunday).');
        }
        if ($endDate !== null && $endDate->format('Y-m-d') < $startDate->format('Y-m-d')) {
            throw new InvalidArgumentException('End date must not be before the start date.');
        }
    }
}