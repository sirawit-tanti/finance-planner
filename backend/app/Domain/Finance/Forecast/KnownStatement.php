<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** A statement that already exists in the database with money still owed. */
final class KnownStatement
{
    public readonly BigDecimal $outstanding;

    public function __construct(
        public readonly DateTimeImmutable $statementDate,
        public readonly DateTimeImmutable $dueDate,
        BigDecimal|string $outstanding,
    ) {
        $this->outstanding = MoneyMath::exact2($outstanding);

        if ($this->outstanding->isNegative()) {
            throw new InvalidArgumentException('Outstanding must not be negative.');
        }
    }
}