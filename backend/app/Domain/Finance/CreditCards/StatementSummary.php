<?php

declare(strict_types=1);

namespace App\Domain\Finance\CreditCards;

use Brick\Math\BigDecimal;

/** Calculated figures of one statement. All money values have scale 2. */
final class StatementSummary
{
    public function __construct(
        /** Total billed this cycle (never negative). */
        public readonly BigDecimal $statementAmount,
        /** > 0 only when refunds exceeded everything billed. Shown to the user, NOT carried forward. */
        public readonly BigDecimal $creditBalance,
        public readonly BigDecimal $paidAmount,
        /** Still to pay (never negative). */
        public readonly BigDecimal $outstanding,
        /** 'unpaid' | 'partial' | 'paid' (overdue is derived from the due date, see StatementCalculator::isOverdue). */
        public readonly string $status,
    ) {
    }
}