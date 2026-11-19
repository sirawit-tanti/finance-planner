<?php

declare(strict_types=1);

namespace App\Domain\Finance\CreditCards;

use DateTimeImmutable;

/** One billing cycle of a card. cycleStart..statementDate is the range of purchase dates it contains. */
final class StatementCycle
{
    public function __construct(
        public readonly DateTimeImmutable $cycleStart,
        public readonly DateTimeImmutable $statementDate,
        public readonly DateTimeImmutable $dueDate,
    ) {
    }
}