<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** A change to ONE occurrence of a recurring rule (different amount, different date, or skipped). */
final class RuleOverride
{
    public readonly ?BigDecimal $amount;

    public function __construct(
        BigDecimal|string|null $amount = null,
        public readonly ?DateTimeImmutable $date = null,
        public readonly bool $skipped = false,
    ) {
        $this->amount = $amount === null ? null : MoneyMath::exact2($amount);
    }
}