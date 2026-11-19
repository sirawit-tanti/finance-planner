<?php

declare(strict_types=1);

namespace App\Domain\Finance\CreditCards;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** A payment made towards a card statement (date + amount only). */
final class CardPayment
{
    public readonly BigDecimal $amount;

    public function __construct(public readonly DateTimeImmutable $date, BigDecimal|string $amount)
    {
        $this->amount = MoneyMath::exact2($amount);

        if (! $this->amount->isPositive()) {
            throw new InvalidArgumentException('A payment must be greater than 0.');
        }
    }
}