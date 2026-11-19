<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validated input for building a schedule. Never guesses missing conditions:
 * anything not stated (interest method, fee handling) must be given explicitly.
 */
final class InstallmentTerms
{
    public const MAX_TERMS = 360;

    /** Principal that is financed (price minus down payment), money scale 2. */
    public readonly BigDecimal $financedAmount;

    /** Annual rate in PERCENT (12 = 12%). Meaning depends on method: flat/nominal/effective. */
    public readonly BigDecimal $annualRatePercent;

    public readonly BigDecimal $feeAmount;

    public function __construct(
        public readonly InstallmentMethod $method,
        BigDecimal|string $financedAmount,
        BigDecimal|string $annualRatePercent,
        public readonly int $termCount,
        public readonly Frequency $frequency,
        public readonly DateTimeImmutable $startDate,
        public readonly DateTimeImmutable $firstDueDate,
        BigDecimal|string $feeAmount = '0',
        public readonly FeeMode $feeMode = FeeMode::None,
    ) {
        $this->financedAmount = MoneyMath::exact2($financedAmount);
        $this->feeAmount = MoneyMath::exact2($feeAmount);
        $this->annualRatePercent = $annualRatePercent instanceof BigDecimal
            ? $annualRatePercent
            : BigDecimal::of($annualRatePercent);

        if (! $this->financedAmount->isPositive()) {
            throw new InvalidArgumentException('Financed amount must be greater than 0.');
        }
        if ($this->annualRatePercent->isNegative()) {
            throw new InvalidArgumentException('Interest rate must not be negative.');
        }
        if ($this->method === InstallmentMethod::Zero && ! $this->annualRatePercent->isZero()) {
            throw new InvalidArgumentException('A 0% installment must have a rate of 0.');
        }
        if ($termCount < 1 || $termCount > self::MAX_TERMS) {
            throw new InvalidArgumentException('Term count must be between 1 and ' . self::MAX_TERMS . '.');
        }
        if ($this->feeAmount->isNegative()) {
            throw new InvalidArgumentException('Fee must not be negative.');
        }
        if ($this->feeAmount->isPositive() && $feeMode === FeeMode::None) {
            throw new InvalidArgumentException('A fee was given but the fee mode is "none". Choose upfront or spread.');
        }
        if ($firstDueDate < $startDate) {
            throw new InvalidArgumentException('First due date must not be before the start date.');
        }
    }
}