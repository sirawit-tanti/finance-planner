<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use Brick\Math\BigDecimal;

/** Result of building a schedule. All totals are derived from the rows, so they can never disagree. */
final class Schedule
{
    /** @param list<ScheduleItem> $items */
    public function __construct(public readonly array $items)
    {
    }

    public function totalPrincipal(): BigDecimal
    {
        return $this->sum(fn (ScheduleItem $i) => $i->principalPart);
    }

    public function totalInterest(): BigDecimal
    {
        return $this->sum(fn (ScheduleItem $i) => $i->interestPart);
    }

    public function totalFees(): BigDecimal
    {
        return $this->sum(fn (ScheduleItem $i) => $i->feePart);
    }

    public function totalPayable(): BigDecimal
    {
        return $this->sum(fn (ScheduleItem $i) => $i->totalDue);
    }

    /**
     * Amounts of the regular installments (no. >= 1), in order.
     * Used by RateSolver::irr() to find the real effective rate.
     *
     * @return list<BigDecimal>
     */
    public function installmentAmounts(): array
    {
        $amounts = [];
        foreach ($this->items as $item) {
            if ($item->installmentNo >= 1) {
                $amounts[] = $item->totalDue;
            }
        }

        return $amounts;
    }

    /** @param callable(ScheduleItem): BigDecimal $pick */
    private function sum(callable $pick): BigDecimal
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->items as $item) {
            $total = $total->plus($pick($item));
        }

        return $total;
    }
}