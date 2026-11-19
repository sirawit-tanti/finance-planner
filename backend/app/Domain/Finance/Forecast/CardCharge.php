<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** A FUTURE charge to a credit card that is not recorded yet (e.g. an installment row funded by the card). */
final class CardCharge
{
    public readonly BigDecimal $amount;

    public function __construct(
        public readonly DateTimeImmutable $date,
        BigDecimal|string $amount,
        public readonly string $label,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
        $this->amount = MoneyMath::exact2($amount);

        if (! $this->amount->isPositive()) {
            throw new InvalidArgumentException('A card charge must be greater than 0.');
        }
    }
}