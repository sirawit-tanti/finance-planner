<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Result for one financial month. All money values have scale 2. */
final class MonthForecast
{
    public const RISK_OK = 'ok';
    public const RISK_LOW = 'below_reserve'; // lowest balance fell under the emergency fund
    public const RISK_NEGATIVE = 'negative'; // lowest balance went below zero

    /** @param list<CashEvent> $events every event of the month (drill-down, "where does this number come from") */
    public function __construct(
        public readonly string $key,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        /** True for the first month when the forecast starts part-way through it. */
        public readonly bool $isPartial,
        public readonly BigDecimal $openingCash,
        public readonly BigDecimal $incomeRecurring,
        public readonly BigDecimal $incomePlanned,
        public readonly BigDecimal $expenseRecurring,
        public readonly BigDecimal $expensePlanned,
        public readonly BigDecimal $expenseGeneral,
        public readonly BigDecimal $cardPayments,
        public readonly BigDecimal $installmentsTotal,
        public readonly BigDecimal $installmentPrincipal,
        public readonly BigDecimal $installmentInterest,
        public readonly BigDecimal $installmentFees,
        public readonly BigDecimal $savingsReserved,
        public readonly BigDecimal $totalIn,
        public readonly BigDecimal $totalOut,
        public readonly BigDecimal $closingCash,
        public readonly BigDecimal $minBalance,
        public readonly DateTimeImmutable $minBalanceDate,
        public readonly BigDecimal $reservedCumulative,
        /** closing cash - reserved savings - emergency fund */
        public readonly BigDecimal $freeCash,
        public readonly string $risk,
        public readonly array $events,
    ) {
    }
}