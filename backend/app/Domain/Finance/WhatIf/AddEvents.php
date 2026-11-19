<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use App\Domain\Finance\Forecast\CashEvent;

/** Adds events: a new installment, a one-off purchase, extra income, an extra saving... */
final class AddEvents implements ScenarioChange
{
    /** @param list<CashEvent> $added */
    public function __construct(private readonly array $added)
    {
    }

    public function apply(array $events): array
    {
        return array_merge($events, $this->added);
    }
}