<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One money movement on one calendar day. Immutable.
 * sourceType/sourceId say where it came from (drill-down, and What-if edits).
 * principal/interest/feePart are only used by installment events.
 */
final class CashEvent
{
    public readonly DateTimeImmutable $date;
    public readonly BigDecimal $amount;

    public function __construct(
        DateTimeImmutable $date,
        BigDecimal|string $amount,
        public readonly Direction $direction,
        public readonly EventKind $kind,
        public readonly string $label,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly ?BigDecimal $principalPart = null,
        public readonly ?BigDecimal $interestPart = null,
        public readonly ?BigDecimal $feePart = null,
    ) {
        // Calendar day only, in UTC: no time of day or server timezone can leak in.
        $this->date = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
        $this->amount = MoneyMath::exact2($amount);

        if (! $this->amount->isPositive()) {
            throw new InvalidArgumentException("An event amount must be greater than 0 ({$label}).");
        }
    }

    /** Same event with a new amount. Installment parts are dropped because they would no longer add up. */
    public function withAmount(BigDecimal|string $amount): self
    {
        return new self(
            $this->date, $amount, $this->direction, $this->kind, $this->label,
            $this->sourceType, $this->sourceId,
        );
    }
}