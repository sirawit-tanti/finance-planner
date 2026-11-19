<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/** Sets a new amount on every event of one source (e.g. a new salary), optionally from a date on. */
final class SetAmount implements ScenarioChange
{
    private readonly BigDecimal $amount;

    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        BigDecimal|string $amount,
        private readonly ?DateTimeImmutable $fromDate = null,
    ) {
        $this->amount = MoneyMath::exact2($amount);

        if (! $this->amount->isPositive()) {
            throw new InvalidArgumentException('Use RemoveSource to remove an item; the new amount must be greater than 0.');
        }
    }

    public function apply(array $events): array
    {
        $fromDay = $this->fromDate?->format('Y-m-d');

        return array_map(function ($event) use ($fromDay) {
            $matches = $event->sourceType === $this->sourceType
                && $event->sourceId === $this->sourceId
                && ($fromDay === null || $event->date->format('Y-m-d') >= $fromDay);

            return $matches ? $event->withAmount($this->amount) : $event;
        }, $events);
    }
}