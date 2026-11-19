<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Dates\DateRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DateRulesTest extends TestCase
{
    public function test_clamp_day_in_february(): void
    {
        $this->assertSame('2026-02-28', DateRules::clampDay(2026, 2, 31)->format('Y-m-d'));
        $this->assertSame('2028-02-29', DateRules::clampDay(2028, 2, 31)->format('Y-m-d')); // leap year
        $this->assertSame('2026-04-30', DateRules::clampDay(2026, 4, 31)->format('Y-m-d'));
        $this->assertSame('2026-05-31', DateRules::clampDay(2026, 5, 31)->format('Y-m-d'));
    }

    public function test_add_months_keeps_the_anchor_day(): void
    {
        $anchor = new DateTimeImmutable('2026-01-31');
        $this->assertSame('2026-02-28', DateRules::addMonthsClamped($anchor, 1)->format('Y-m-d'));
        $this->assertSame('2026-03-31', DateRules::addMonthsClamped($anchor, 2)->format('Y-m-d'));
    }

    public function test_add_months_crosses_the_year_boundary(): void
    {
        $anchor = new DateTimeImmutable('2026-11-15');
        $this->assertSame('2027-02-15', DateRules::addMonthsClamped($anchor, 3)->format('Y-m-d'));
        $this->assertSame('2027-11-15', DateRules::addMonthsClamped($anchor, 12)->format('Y-m-d'));
    }

    public function test_shift_previous_weekday(): void
    {
        $saturday = new DateTimeImmutable('2026-11-21');
        $sunday = new DateTimeImmutable('2026-11-22');
        $this->assertSame('2026-11-20', DateRules::shift($saturday, DateRules::SHIFT_PREVIOUS_WEEKDAY)->format('Y-m-d'));
        $this->assertSame('2026-11-20', DateRules::shift($sunday, DateRules::SHIFT_PREVIOUS_WEEKDAY)->format('Y-m-d'));
    }

    public function test_shift_next_weekday(): void
    {
        $saturday = new DateTimeImmutable('2026-11-21');
        $sunday = new DateTimeImmutable('2026-11-22');
        $this->assertSame('2026-11-23', DateRules::shift($saturday, DateRules::SHIFT_NEXT_WEEKDAY)->format('Y-m-d'));
        $this->assertSame('2026-11-23', DateRules::shift($sunday, DateRules::SHIFT_NEXT_WEEKDAY)->format('Y-m-d'));
    }

    public function test_shift_leaves_weekdays_and_none_rule_untouched(): void
    {
        $wednesday = new DateTimeImmutable('2026-11-18');
        $saturday = new DateTimeImmutable('2026-11-21');
        $this->assertSame('2026-11-18', DateRules::shift($wednesday, DateRules::SHIFT_NEXT_WEEKDAY)->format('Y-m-d'));
        $this->assertSame('2026-11-21', DateRules::shift($saturday, DateRules::SHIFT_NONE)->format('Y-m-d'));
    }

    public function test_unknown_shift_rule_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateRules::shift(new DateTimeImmutable('2026-11-18'), 'whatever');
    }
}