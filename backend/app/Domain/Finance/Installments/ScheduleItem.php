<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** One row of the schedule. installmentNo 0 is the upfront fee row (if any). */
final class ScheduleItem
{
    public function __construct(
        public readonly int $installmentNo,
        public readonly DateTimeImmutable $dueDate,
        public readonly BigDecimal $principalPart,
        public readonly BigDecimal $interestPart,
        public readonly BigDecimal $feePart,
        public readonly BigDecimal $totalDue,
        public readonly BigDecimal $remainingPrincipalAfter,
    ) {
    }
}