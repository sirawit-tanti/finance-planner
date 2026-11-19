<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\PeriodCalendar;
use App\Domain\Finance\Forecast\SavingsReserveGenerator;
use App\Domain\Finance\Savings\SavingsPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SavingsPlannerTest extends TestCase
{
    public function test_progress_percent(): void
    {
        $planner = new SavingsPlanner();

        $this->assertSame('25.00', (string) $planner->progressPercent('60000', '15000'));
        $this->assertSame('0.00', (string) $planner->progressPercent('60000', '0'));
        $this->assertSame('33.33', (string) $planner->progressPercent('30000', '10000'));
        $this->assertSame('100.00', (string) $planner->progressPercent('60000', '61000')); // capped
    }

    public function test_progress_rejects_a_zero_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SavingsPlanner())->progressPercent('0', '100');
    }

    public function test_months_to_goal(): void
    {
        $planner = new SavingsPlanner();

        $this->assertSame(9, $planner->monthsToGoal('60000', '15000', '5000'));
        $this->assertSame(4, $planner->monthsToGoal('10000', '0', '3000'));   // 3.33 => 4
        $this->assertSame(0, $planner->monthsToGoal('10000', '10000', '3000'));
        $this->assertNull($planner->monthsToGoal('10000', '0', '0'));
    }

    public function test_estimated_date(): void
    {
        $date = (new SavingsPlanner())->estimatedDate(ForecastFixtures::d('2026-11-19'), 9);

        $this->assertSame('2027-08-19', $date->format('Y-m-d'));
    }

    public function test_reserve_events_stop_at_the_target_and_account_for_this_months_contribution(): void
    {
        $events = (new SavingsReserveGenerator())->generate(
            'goal-1', 'ทริปญี่ปุ่น', '5000', '12000', new PeriodCalendar(1),
            ForecastFixtures::d('2026-11-19'), 6, '2000'
        );

        $this->assertSame(
            [['2026-11-19', '3000.00'], ['2026-12-01', '5000.00'], ['2027-01-01', '4000.00']],
            array_map(fn ($e) => [$e->date->format('Y-m-d'), (string) $e->amount], $events)
        );
        $this->assertSame(Direction::Reserve, $events[0]->direction);
        $this->assertSame(EventKind::SavingsReserve, $events[0]->kind);
        $this->assertSame('goal-1', $events[0]->sourceId);
    }

    public function test_no_reserve_is_created_when_the_goal_is_already_reached(): void
    {
        $this->assertSame([], (new SavingsReserveGenerator())->generate(
            'goal-1', 'x', '5000', '0', new PeriodCalendar(1), ForecastFixtures::d('2026-11-19'), 6
        ));
    }

    public function test_recommended_saving_is_the_smallest_complete_month_surplus_rounded_down(): void
    {
        // Dec surplus = 32,000 - (7,700 + 2,800 + 5,000) = 16,500; Jan = 24,300; Nov is partial and ignored.
        $forecast = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $this->assertSame('16500.00', (string) (new SavingsPlanner())->recommendedMonthly($forecast));
    }

    public function test_recommended_saving_rounds_down_to_a_hundred(): void
    {
        $events = array_merge(ForecastFixtures::baseEvents(), [
            ForecastFixtures::event('2026-12-12', '70.55', Direction::Out, EventKind::ExpensePlanned),
        ]); // Dec surplus 16,429.45

        $this->assertSame('16400.00', (string) (new SavingsPlanner())->recommendedMonthly(ForecastFixtures::run($events)));
    }

    public function test_recommended_saving_is_zero_when_a_month_has_no_surplus(): void
    {
        $events = array_merge(ForecastFixtures::baseEvents(), [
            ForecastFixtures::event('2026-12-12', '20000', Direction::Out, EventKind::ExpensePlanned),
        ]); // Dec surplus -3,500

        $this->assertSame('0.00', (string) (new SavingsPlanner())->recommendedMonthly(ForecastFixtures::run($events)));
    }

    public function test_recommended_saving_is_zero_when_only_a_partial_month_exists(): void
    {
        $forecast = ForecastFixtures::run(ForecastFixtures::baseEvents(), 1);

        $this->assertSame('0.00', (string) (new SavingsPlanner())->recommendedMonthly($forecast));
    }
}