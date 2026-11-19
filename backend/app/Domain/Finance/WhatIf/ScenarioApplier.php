<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use App\Domain\Finance\Forecast\CashEvent;

final class ScenarioApplier
{
    /**
     * Applies the changes in order. The input list is never modified.
     *
     * @param list<CashEvent>      $events
     * @param list<ScenarioChange> $changes
     * @return list<CashEvent>
     */
    public function apply(array $events, array $changes): array
    {
        foreach ($changes as $change) {
            $events = $change->apply($events);
        }

        return $events;
    }
}