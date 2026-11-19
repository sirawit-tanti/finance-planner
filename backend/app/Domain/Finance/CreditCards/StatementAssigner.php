<?php

declare(strict_types=1);

namespace App\Domain\Finance\CreditCards;

use App\Domain\Finance\Dates\DateRules;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Decides which statement a date belongs to.
 *
 * Rules (Requirement section 6, Phase 2 section 5):
 *  - date <= statement day of its month  => statement of that month
 *    (a purchase ON the statement day is in that statement)
 *  - date >  statement day               => statement of the next month
 *  - due_day >  statement_day            => due in the statement's month
 *    due_day <= statement_day            => due in the month after
 *  - days 29-31 are clamped to the last day of short months
 *
 * Used for card purchases AND for installment rows charged to a card
 * (the row's due date is the day it is charged).
 */
final class StatementAssigner
{
    public function cycleFor(int $statementDay, int $dueDay, DateTimeImmutable $date): StatementCycle
    {
        $this->assertDays($statementDay, $dueDay);

        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $statementThisMonth = DateRules::clampDay($year, $month, $statementDay);

        // Compare as plain calendar days so a time-of-day on $date can never matter.
        if ($date->format('Y-m-d') > $statementThisMonth->format('Y-m-d')) {
            [$year, $month] = self::shiftMonth($year, $month, 1);
        }

        return $this->cycleForStatementMonth($statementDay, $dueDay, $year, $month);
    }

    /** The cycle whose statement date falls in the given calendar month. */
    public function cycleForStatementMonth(int $statementDay, int $dueDay, int $year, int $month): StatementCycle
    {
        $this->assertDays($statementDay, $dueDay);

        $statementDate = DateRules::clampDay($year, $month, $statementDay);

        [$prevYear, $prevMonth] = self::shiftMonth($year, $month, -1);
        $cycleStart = DateRules::clampDay($prevYear, $prevMonth, $statementDay)->modify('+1 day');

        [$dueYear, $dueMonth] = $dueDay > $statementDay
            ? [$year, $month]
            : self::shiftMonth($year, $month, 1);
        $dueDate = DateRules::clampDay($dueYear, $dueMonth, $dueDay);

        return new StatementCycle($cycleStart, $statementDate, $dueDate);
    }

    /** @return array{0: int, 1: int} [year, month] */
    private static function shiftMonth(int $year, int $month, int $delta): array
    {
        $index = $year * 12 + ($month - 1) + $delta;

        return [intdiv($index, 12), $index % 12 + 1];
    }

    private function assertDays(int $statementDay, int $dueDay): void
    {
        foreach (['statement day' => $statementDay, 'due day' => $dueDay] as $label => $day) {
            if ($day < 1 || $day > 31) {
                throw new InvalidArgumentException("The {$label} must be between 1 and 31.");
            }
        }
    }
}