<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Dates\DateRules;
use App\Domain\Finance\Installments\Frequency;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Turns a RecurringRule into events inside [from, to] (by EVENT date).
 *
 * For each occurrence: skip if settled or skipped by an override, take the override
 * amount/date if there is one, otherwise the rule's amount and the shift rule.
 * Occurrences before the rule's start date or after its end date never exist.
 * Monthly/yearly anchor to the day-of-month with month-end clamping (31 => 30 / 28).
 */
final class RecurrenceGenerator
{
    /** @return list<CashEvent> */
    public function generate(RecurringRule $rule, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (! $rule->isActive) {
            return [];
        }

        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        // A weekend shift moves a date by at most 2 days, so look 3 days past the window.
        $limit = self::day($to)->modify('+3 days')->format('Y-m-d');
        $endDay = $rule->endDate?->format('Y-m-d');

        $sourceType = $rule->direction === Direction::In ? 'income_source' : 'recurring_expense';
        $events = [];

        foreach ($this->occurrences($rule, self::day($from), $limit) as $occurrence) {
            $key = $occurrence->format('Y-m-d');
            if ($endDay !== null && $key > $endDay) {
                break;
            }
            if (isset($rule->settled[$key])) {
                continue;
            }

            $override = $rule->overrides[$key] ?? null;
            if ($override !== null && $override->skipped) {
                continue;
            }

            $amount = $override?->amount ?? $rule->amount;
            if (! $amount->isPositive()) {
                continue;
            }

            $date = $override?->date ?? DateRules::shift($occurrence, $rule->shiftRule);
            $day = $date->format('Y-m-d');
            if ($day < $fromDay || $day > $toDay) {
                continue;
            }

            $events[] = new CashEvent(
                $date, $amount, $rule->direction, $rule->kind, $rule->name, $sourceType, $rule->id
            );
        }

        return $events;
    }

    /** Original occurrence dates in ascending order, starting near $from. */
    private function occurrences(RecurringRule $rule, DateTimeImmutable $from, string $limit): Generator
    {
        $start = self::day($rule->startDate);
        $startDay = $start->format('Y-m-d');

        switch ($rule->frequency) {
            case Frequency::Monthly:
                $startIndex = ((int) $start->format('Y')) * 12 + ((int) $start->format('n') - 1);
                $fromIndex = ((int) $from->format('Y')) * 12 + ((int) $from->format('n') - 1);
                for ($i = max($startIndex, $fromIndex - 1);; $i++) {
                    $occurrence = DateRules::clampDay(intdiv($i, 12), $i % 12 + 1, (int) $rule->dayOfMonth);
                    if ($occurrence->format('Y-m-d') > $limit) {
                        return;
                    }
                    if ($occurrence->format('Y-m-d') >= $startDay) {
                        yield $occurrence;
                    }
                }
                break;

            case Frequency::Yearly:
                $month = (int) $start->format('n');
                for ($year = max((int) $start->format('Y'), ((int) $from->format('Y')) - 1);; $year++) {
                    $occurrence = DateRules::clampDay($year, $month, (int) $rule->dayOfMonth);
                    if ($occurrence->format('Y-m-d') > $limit) {
                        return;
                    }
                    if ($occurrence->format('Y-m-d') >= $startDay) {
                        yield $occurrence;
                    }
                }
                break;

            default: // weekly / biweekly
                $step = $rule->frequency === Frequency::Weekly ? 7 : 14;
                $delta = (((int) $rule->weekday) - ((int) $start->format('N')) + 7) % 7;
                $first = $start->modify("+{$delta} days");

                $skip = 0;
                if ($first->format('Y-m-d') < $from->format('Y-m-d')) {
                    $skip = max(0, intdiv((int) $first->diff($from)->days, $step) - 1);
                }

                for ($occurrence = $first->modify('+' . ($skip * $step) . ' days');
                     $occurrence->format('Y-m-d') <= $limit;
                     $occurrence = $occurrence->modify("+{$step} days")) {
                    yield $occurrence;
                }
        }
    }

    private static function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    }
}