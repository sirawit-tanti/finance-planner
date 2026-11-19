<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use Brick\Math\BigDecimal;

final class ScenarioComparison
{
    /**
     * @param list<MonthDiff> $months
     * @param list<string>    $newRiskMonths      month keys that became riskier
     * @param list<string>    $resolvedRiskMonths month keys that became safer
     */
    public function __construct(
        public readonly array $months,
        public readonly BigDecimal $finalClosingDelta,
        public readonly BigDecimal $lowestBalanceBefore,
        public readonly BigDecimal $lowestBalanceAfter,
        /** Change in total installment payments over the horizon (positive = more debt service). */
        public readonly BigDecimal $extraDebtService,
        public readonly BigDecimal $extraInterest,
        /** Change in total savings reserved at the end of the horizon. */
        public readonly BigDecimal $savingsReservedDelta,
        public readonly array $newRiskMonths,
        public readonly array $resolvedRiskMonths,
    ) {
    }
}