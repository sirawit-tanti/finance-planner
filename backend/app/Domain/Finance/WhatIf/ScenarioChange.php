<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use App\Domain\Finance\Forecast\CashEvent;

/** One hypothetical change, applied to a list of events. Never touches real data. */
interface ScenarioChange
{
    /**
     * @param list<CashEvent> $events
     * @return list<CashEvent>
     */
    public function apply(array $events): array;
}