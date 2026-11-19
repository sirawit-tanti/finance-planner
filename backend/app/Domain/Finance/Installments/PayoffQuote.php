<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use Brick\Math\BigDecimal;

/** Early payoff figures. All money values have scale 2. */
final class PayoffQuote
{
    public function __construct(
        public readonly BigDecimal $remainingPrincipal,
        public readonly BigDecimal $remainingInterest,
        public readonly BigDecimal $remainingFees,
        /** principal + interest + fees still on the schedule (what paying every row as planned would cost). */
        public readonly BigDecimal $scheduledRemaining,
        /** Entered by the user. Never assumed. */
        public readonly BigDecimal $interestDiscount,
        /** Entered by the user. Never assumed. */
        public readonly BigDecimal $earlyFee,
        public readonly BigDecimal $payoffAmount,
        /** Regular installments (no. >= 1) still pending. */
        public readonly int $remainingInstallments,
    ) {
    }
}