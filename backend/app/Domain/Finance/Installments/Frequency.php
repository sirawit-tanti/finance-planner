<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use App\Domain\Finance\Dates\DateRules;
use DateTimeImmutable;

enum Frequency: string
{
    case Monthly = 'monthly';
    case Biweekly = 'biweekly';
    case Weekly = 'weekly';
    case Yearly = 'yearly';

    /** Number of periods in a year, used to convert an annual rate to a per-period rate. */
    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Biweekly => 26,
            self::Weekly => 52,
            self::Yearly => 1,
        };
    }

    /** Due date of installment #(index + 1), where $index is 0-based. */
    public function dueDate(DateTimeImmutable $firstDue, int $index): DateTimeImmutable
    {
        return match ($this) {
            self::Monthly => DateRules::addMonthsClamped($firstDue, $index),
            self::Yearly => DateRules::addMonthsClamped($firstDue, 12 * $index),
            self::Biweekly => $firstDue->modify('+' . (14 * $index) . ' days'),
            self::Weekly => $firstDue->modify('+' . (7 * $index) . ' days'),
        };
    }
}