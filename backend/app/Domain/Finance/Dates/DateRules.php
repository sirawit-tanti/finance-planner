<?php

declare(strict_types=1);

namespace App\Domain\Finance\Dates;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Calendar rules shared by installments, credit cards and the forecast.
 * Dates are plain calendar days (no time of day); everything is built in UTC
 * at midnight so daylight-saving or server timezone can never shift a day.
 */
final class DateRules
{
    public const SHIFT_NONE = 'none';
    public const SHIFT_PREVIOUS_WEEKDAY = 'previous_weekday';
    public const SHIFT_NEXT_WEEKDAY = 'next_weekday';

    /** Day 31 in a 30-day month (or 29/30/31 in Febuary) becomes the last day of that month. */
    public static function clampDay(int $year, int $month, int $day): DateTimeImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1) {
            throw new InvalidArgumentException("Invalid date parts: {$year}-{$month}-{$day}");
        }

        $firstOfMonth = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            new DateTimeZone('UTC')
        );
        $lastDay = (int) $firstOfMonth->format('t');

        return $firstOfMonth->setDate($year, $month, min($day, $lastDay));
    }

    /**
     * Add whole months while keeping the ANCHOR's day-of-month, clamped.
     * Always computed from the anchor (not chained), so 31 Jan + 1 month = 28 Feb
     * but 31 Jan + 2 months = 31 Mar (not 28 Mar).
     */
    public static function addMonthsClamped(DateTimeImmutable $anchor, int $months): DateTimeImmutable
    {
        if ($months < 0) {
            throw new InvalidArgumentException("Months must be >= 0.");
        }

        $index = ((int) $anchor->format('Y')) * 12 + ((int) $anchor->format('n') - 1) + $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;

        return self::clampDay($year, $month, (int) $anchor->format('j'));
    }

    /**
     * Optional per-item shift when a due date falls on Saturday/Sunday.
     * No public-holiday calendar (agreed: weekends only).
     */
    public static function shift(DateTimeImmutable $date, string $rule): DateTimeImmutable
    {
        $dayOfWeek = (int) $date->format('N');

        return match ($rule) {
            self::SHIFT_NONE => $date,
            self::SHIFT_PREVIOUS_WEEKDAY => match ($dayOfWeek) {
                6 => $date->modify('-1 day'),
                7 => $date->modify('-2 days'),
                default => $date,
            },
            self::SHIFT_NEXT_WEEKDAY => match ($dayOfWeek) {
                6 => $date->modify('+2 days'),
                7 => $date->modify('+1 day'),
                default => $date,
            },
            default => throw new InvalidArgumentException("Unknown shift rule: {$rule}"),
        };
    }
}