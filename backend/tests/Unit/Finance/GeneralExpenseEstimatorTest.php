<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\GeneralExpenseEstimator;
use App\Domain\Finance\Forecast\PeriodCalendar;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

class GeneralExpenseEstimatorTest extends TestCase
{
    /** @param list<CashEvent> $events */
    private function total(array $events, string $monthPrefix): string
    {
        $sum = BigDecimal::of('0.00');
        foreach ($events as $e) {
            if (str_starts_with($e->date->format('Y-m-d'), $monthPrefix)) {
                $sum = $sum->plus($e->amount);
            }
        }

        return (string) $sum;
    }

    /** @param list<CashEvent> $events */
    private function countInMonth(array $events, string $monthPrefix): int
    {
        return count(array_filter($events, fn ($e) => str_starts_with($e->date->format('Y-m-d'), $monthPrefix)));
    }

    public function test_current_month_spreads_what_is_left_over_the_remaining_days(): void
    {
        $events = (new GeneralExpenseEstimator())->generate(
            new PeriodCalendar(1), ForecastFixtures::d('2026-11-19'), 2, '30000', '12000'
        );

        $this->assertSame(12, $this->countInMonth($events, '2026-11'));   // 19 Nov .. 30 Nov
        $this->assertSame('18000.00', $this->total($events, '2026-11'));
        $this->assertSame('1500.00', (string) $events[0]->amount);
        $this->assertSame('2026-11-19', $events[0]->date->format('Y-m-d'));
    }

    public function test_future_months_use_the_full_amount_and_sum_exactly(): void
    {
        $events = (new GeneralExpenseEstimator())->generate(
            new PeriodCalendar(1), ForecastFixtures::d('2026-11-19'), 2, '30000', '12000'
        );

        $this->assertSame(31, $this->countInMonth($events, '2026-12'));
        $this->assertSame('30000.00', $this->total($events, '2026-12'));
    }

    public function test_nothing_is_forecast_when_the_budget_is_already_used_up(): void
    {
        $events = (new GeneralExpenseEstimator())->generate(
            new PeriodCalendar(1), ForecastFixtures::d('2026-11-19'), 2, '30000', '30000'
        );

        $this->assertSame(0, $this->countInMonth($events, '2026-11'));
        $this->assertSame('30000.00', $this->total($events, '2026-12'));
    }

    public function test_overspent_month_does_not_create_negative_events(): void
    {
        $events = (new GeneralExpenseEstimator())->generate(
            new PeriodCalendar(1), ForecastFixtures::d('2026-11-19'), 1, '30000', '35000'
        );

        $this->assertSame([], $events);
    }

    public function test_fallback_average_of_recent_months(): void
    {
        $this->assertSame('166.67', (string) GeneralExpenseEstimator::fallbackAverage(['100.00', '200.00', '200.01']));
        $this->assertSame('0.00', (string) GeneralExpenseEstimator::fallbackAverage([]));
    }
}