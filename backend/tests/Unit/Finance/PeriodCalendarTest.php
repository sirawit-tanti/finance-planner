<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\PeriodCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodCalendarTest extends TestCase
{
    /** @return array{0: string, 1: string, 2: string} */
    private function bounds(int $startDay, string $date): array
    {
        $p = (new PeriodCalendar($startDay))->containing(ForecastFixtures::d($date));

        return [$p->key, $p->start->format('Y-m-d'), $p->end->format('Y-m-d')];
    }

    public function test_calendar_months_when_the_start_day_is_1(): void
    {
        $this->assertSame(['2026-11', '2026-11-01', '2026-11-30'], $this->bounds(1, '2026-11-19'));
        $this->assertSame(['2028-02', '2028-02-01', '2028-02-29'], $this->bounds(1, '2028-02-10'));
    }

    public function test_start_day_25_before_and_on_the_start_day(): void
    {
        $this->assertSame(['2026-10', '2026-10-25', '2026-11-24'], $this->bounds(25, '2026-11-19'));
        $this->assertSame(['2026-11', '2026-11-25', '2026-12-24'], $this->bounds(25, '2026-11-25'));
    }

    public function test_start_day_25_across_the_year_boundary(): void
    {
        $this->assertSame(['2026-12', '2026-12-25', '2027-01-24'], $this->bounds(25, '2026-12-30'));
        $this->assertSame(['2026-12', '2026-12-25', '2027-01-24'], $this->bounds(25, '2027-01-10'));
    }

    public function test_periods_are_consecutive_without_gaps(): void
    {
        $periods = (new PeriodCalendar(25))->periods(ForecastFixtures::d('2026-11-19'), 14);

        $this->assertCount(14, $periods);
        for ($i = 1; $i < 14; $i++) {
            $this->assertSame(
                $periods[$i - 1]->end->modify('+1 day')->format('Y-m-d'),
                $periods[$i]->start->format('Y-m-d')
            );
        }
        $this->assertSame('2026-10', $periods[0]->key);
    }

    public function test_start_day_out_of_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PeriodCalendar(29);
    }
}