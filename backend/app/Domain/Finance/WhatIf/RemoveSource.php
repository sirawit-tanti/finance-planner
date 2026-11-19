<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use DateTimeImmutable;

/**
 * Removes every event of one source (a recurring expense, a plan, a goal...),
 * optionally only from a date on. Used for "cut this expense" and, together with
 * AddEvents(payoff event), for "pay this plan off early".
 */
final class RemoveSource implements ScenarioChange
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $sourceId,
        private readonly ?DateTimeImmutable $fromDate = null,
    ) {
    }

    public function apply(array $events): array
    {
        $fromDay = $this->fromDate?->format('Y-m-d');

        return array_values(array_filter($events, function ($event) use ($fromDay): bool {
            $matches = $event->sourceType === $this->sourceType
                && $event->sourceId === $this->sourceId
                && ($fromDay === null || $event->date->format('Y-m-d') >= $fromDay);

            return ! $matches;
        }));
    }
}