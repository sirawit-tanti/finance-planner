<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\WhatIf\AddEvents;
use App\Domain\Finance\WhatIf\RemoveSource;
use App\Domain\Finance\WhatIf\ScenarioApplier;
use App\Domain\Finance\WhatIf\ScenarioComparator;
use App\Domain\Finance\WhatIf\SetAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WhatIfTest extends TestCase
{
    private ScenarioApplier $applier;
    private ScenarioComparator $comparator;

    protected function setUp(): void
    {
        $this->applier = new ScenarioApplier();
        $this->comparator = new ScenarioComparator();
    }

    /** @return list<string> */
    private function deltas(\App\Domain\Finance\WhatIf\ScenarioComparison $c): array
    {
        return array_map(fn ($m) => (string) $m->closingDelta, $c->months);
    }

    public function test_cutting_an_expense_from_december_improves_december_and_january(): void
    {
        $before = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $events = $this->applier->apply(ForecastFixtures::baseEvents(), [
            new RemoveSource('recurring_expense', 'mortgage', ForecastFixtures::d('2026-12-01')),
        ]);
        $comparison = $this->comparator->compare($before, ForecastFixtures::run($events));

        $this->assertSame(['0.00', '7700.00', '15400.00'], $this->deltas($comparison));
        $this->assertSame('15400.00', (string) $comparison->finalClosingDelta);
    }

    public function test_a_new_salary_from_december(): void
    {
        $before = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $events = $this->applier->apply(ForecastFixtures::baseEvents(), [
            new SetAmount('income_source', 'salary', '35000', ForecastFixtures::d('2026-12-01')),
        ]);
        $comparison = $this->comparator->compare($before, ForecastFixtures::run($events));

        $this->assertSame(['0.00', '3000.00', '6000.00'], $this->deltas($comparison));
    }

    public function test_a_new_installment_adds_debt_service(): void
    {
        $before = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $events = $this->applier->apply(ForecastFixtures::baseEvents(), [
            new AddEvents([
                ForecastFixtures::event('2026-12-15', '2400', Direction::Out, EventKind::Installment, 'ผ่อนโน้ตบุ๊ก', 'installment_plan', 'plan-new', '2400', '0', '0'),
                ForecastFixtures::event('2027-01-15', '2400', Direction::Out, EventKind::Installment, 'ผ่อนโน้ตบุ๊ก', 'installment_plan', 'plan-new', '2400', '0', '0'),
            ]),
        ]);
        $comparison = $this->comparator->compare($before, ForecastFixtures::run($events));

        $this->assertSame('4800.00', (string) $comparison->extraDebtService);
        $this->assertSame('0.00', (string) $comparison->extraInterest);
        $this->assertSame('-4800.00', (string) $comparison->finalClosingDelta);
        $this->assertSame([], $comparison->newRiskMonths);
    }

    public function test_extra_savings_change_the_reserve_not_the_cash(): void
    {
        $before = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $events = $this->applier->apply(ForecastFixtures::baseEvents(), [
            new AddEvents([ForecastFixtures::event('2026-12-01', '1000', Direction::Reserve, EventKind::SavingsReserve, 'ออมเพิ่ม')]),
        ]);
        $comparison = $this->comparator->compare($before, ForecastFixtures::run($events));

        $this->assertSame('1000.00', (string) $comparison->savingsReservedDelta);
        $this->assertSame('0.00', (string) $comparison->finalClosingDelta);
    }

    public function test_a_month_that_becomes_risky_is_reported(): void
    {
        $base = [ForecastFixtures::event('2026-11-20', '2000', Direction::Out, EventKind::ExpensePlanned, 'a')];
        $before = ForecastFixtures::run($base, 1, '3000', '0');
        $events = $this->applier->apply($base, [
            new AddEvents([ForecastFixtures::event('2026-11-21', '2000', Direction::Out, EventKind::ExpensePlanned, 'b')]),
        ]);
        $after = ForecastFixtures::run($events, 1, '3000', '0');
        $comparison = $this->comparator->compare($before, $after);

        $this->assertSame('1000.00', (string) $comparison->lowestBalanceBefore);
        $this->assertSame('-1000.00', (string) $comparison->lowestBalanceAfter);
        $this->assertSame(['2026-11'], $comparison->newRiskMonths);
        $this->assertSame([], $comparison->resolvedRiskMonths);
        $this->assertSame(['2026-11'], $this->comparator->compare($after, $before)->resolvedRiskMonths);
    }

    public function test_applying_changes_never_modifies_the_original_events(): void
    {
        $original = ForecastFixtures::baseEvents();
        $this->applier->apply($original, [
            new RemoveSource('recurring_expense', 'mortgage'),
            new SetAmount('income_source', 'salary', '1'),
        ]);

        $this->assertCount(12, $original);
        $this->assertSame('32000.00', (string) $original[2]->amount);
    }

    public function test_changes_are_applied_in_order(): void
    {
        $events = $this->applier->apply(ForecastFixtures::baseEvents(), [
            new SetAmount('income_source', 'salary', '40000'),
            new RemoveSource('income_source', 'salary', ForecastFixtures::d('2027-01-01')),
        ]);

        $salaries = array_values(array_filter($events, fn ($e) => $e->sourceId === 'salary'));
        $this->assertCount(2, $salaries);
        $this->assertSame('40000.00', (string) $salaries[0]->amount);
    }

    public function test_comparing_forecasts_of_different_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->comparator->compare(
            ForecastFixtures::run(ForecastFixtures::baseEvents(), 3),
            ForecastFixtures::run(ForecastFixtures::baseEvents(), 2)
        );
    }

    public function test_set_amount_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SetAmount('income_source', 'salary', '0');
    }
}