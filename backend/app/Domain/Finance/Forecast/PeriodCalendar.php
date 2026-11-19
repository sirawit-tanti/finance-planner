<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Dates\DateRules;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Financial months driven by the "month start day" setting (1-28, so no clamping needed).
 * startDay = 1 gives calendar months; startDay = 25 gives 25th to 24th.
 */
final class PeriodCalendar
{
    public function __construct(public readonly int $startDay = 1)
    {
        if ($startDay < 1 || $startDay > 28) {
            throw new InvalidArgumentException('Month start day must be between 1 and 28.');
        }
    }

    public function containing(DateTimeImmutable $date): Period
    {
        return $this->periodAtIndex($this->indexFor($date));
    }

    /** @return list<Period> $count consecutive periods, the first one containing $from */
    public function periods(DateTimeImmutable $from, int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Count must be >= 1.');
        }

        $first = $this->indexFor($from);
        $periods = [];
        for ($i = 0; $i < $count; $i++) {
            $periods[] = $this->periodAtIndex($first + $i);
        }

        return $periods;
    }

    private function indexFor(DateTimeImmutable $date): int
    {
        $index = ((int) $date->format('Y')) * 12 + ((int) $date->format('n') - 1);

        return ((int) $date->format('j')) < $this->startDay ? $index - 1 : $index;
    }

    private function periodAtIndex(int $index): Period
    {
        $start = DateRules::clampDay(intdiv($index, 12), $index % 12 + 1, $this->startDay);
        $next = DateRules::clampDay(intdiv($index + 1, 12), ($index + 1) % 12 + 1, $this->startDay);

        return new Period($start->format('Y-m'), $start, $next->modify('-1 day'));
    }
}