<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Dates\DateRules;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\RecurrenceGenerator;
use App\Domain\Finance\Forecast\RecurringRule;
use App\Domain\Finance\Forecast\RuleOverride;
use App\Domain\Finance\Installments\Frequency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RecurrenceGeneratorTest extends TestCase
{
    /** @param array<string, RuleOverride> $overrides @param array<string, true> $settled */
    private function salary(
        Frequency $frequency = Frequency::Monthly,
        ?int $day = 25,
        ?int $weekday = null,
        string $start = '2026-01-01',
        ?string $end = null,
        string $shift = DateRules::SHIFT_NONE,
        bool $active = true,
        array $overrides = [],
        array $settled = [],
        string $amount = '32000',
    ): RecurringRule {
        return new RecurringRule(
            'salary', 'เงินเดือน', $amount, $frequency, $day, $weekday,
            ForecastFixtures::d($start), $end === null ? null : ForecastFixtures::d($end),
            $shift, Direction::In, EventKind::IncomeRecurring, $active, $overrides, $settled
        );
    }

    /** @return list<string> */
    private function dates(RecurringRule $rule, string $from, string $to): array
    {
        $events = (new RecurrenceGenerator())->generate($rule, ForecastFixtures::d($from), ForecastFixtures::d($to));

        return array_map(fn ($e) => $e->date->format('Y-m-d'), $events);
    }

    public function test_monthly_on_the_25th(): void
    {
        $this->assertSame(
            ['2026-11-25', '2026-12-25', '2027-01-25', '2027-02-25'],
            $this->dates($this->salary(), '2026-11-19', '2027-02-28')
        );
    }

    public function test_events_carry_the_source_for_drill_down(): void
    {
        $event = (new RecurrenceGenerator())->generate(
            $this->salary(), ForecastFixtures::d('2026-11-19'), ForecastFixtures::d('2026-11-30')
        )[0];

        $this->assertSame('income_source', $event->sourceType);
        $this->assertSame('salary', $event->sourceId);
        $this->assertSame('32000.00', (string) $event->amount);
    }

    public function test_day_31_is_clamped_in_short_months(): void
    {
        $this->assertSame(
            ['2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'],
            $this->dates($this->salary(day: 31), '2026-11-19', '2027-02-28')
        );
    }

    public function test_the_rule_does_not_exist_before_its_start_date(): void
    {
        $this->assertSame(
            ['2026-12-05', '2027-01-05'],
            $this->dates($this->salary(day: 5, start: '2026-11-20'), '2026-11-19', '2027-01-31')
        );
    }

    public function test_the_rule_stops_at_its_end_date(): void
    {
        $this->assertSame(
            ['2026-11-25', '2026-12-25'],
            $this->dates($this->salary(end: '2026-12-31'), '2026-11-19', '2027-02-28')
        );
    }

    public function test_weekend_shift_rules(): void
    {
        // 21 Nov 2026 is a Saturday.
        $this->assertSame(['2026-11-23'], $this->dates($this->salary(day: 21, shift: DateRules::SHIFT_NEXT_WEEKDAY), '2026-11-19', '2026-11-30'));
        $this->assertSame(['2026-11-20'], $this->dates($this->salary(day: 21, shift: DateRules::SHIFT_PREVIOUS_WEEKDAY), '2026-11-19', '2026-11-30'));
        $this->assertSame(['2026-11-21'], $this->dates($this->salary(day: 21), '2026-11-19', '2026-11-30'));
    }

    public function test_a_settled_occurrence_is_not_forecast_again(): void
    {
        $this->assertSame(
            ['2026-12-25', '2027-01-25'],
            $this->dates($this->salary(settled: ['2026-11-25' => true]), '2026-11-19', '2027-01-31')
        );
    }

    public function test_override_amount_date_and_skip(): void
    {
        $rule = $this->salary(overrides: [
            '2026-12-25' => new RuleOverride(amount: '40000'),
            '2027-01-25' => new RuleOverride(skipped: true),
            '2026-11-25' => new RuleOverride(date: ForecastFixtures::d('2026-11-20')),
        ]);
        $events = (new RecurrenceGenerator())->generate($rule, ForecastFixtures::d('2026-11-19'), ForecastFixtures::d('2027-02-28'));

        $this->assertSame(
            ['2026-11-20', '2026-12-25', '2027-02-25'],
            array_map(fn ($e) => $e->date->format('Y-m-d'), $events)
        );
        $this->assertSame('40000.00', (string) $events[1]->amount);
        $this->assertSame('32000.00', (string) $events[0]->amount);
    }

    public function test_an_inactive_rule_creates_nothing(): void
    {
        $this->assertSame([], $this->dates($this->salary(active: false), '2026-11-19', '2027-02-28'));
    }

    public function test_weekly_every_friday(): void
    {
        // Start Sunday 1 Nov 2026 => first Friday is 6 Nov.
        $this->assertSame(
            ['2026-11-20', '2026-11-27', '2026-12-04'],
            $this->dates($this->salary(Frequency::Weekly, day: null, weekday: 5, start: '2026-11-01'), '2026-11-19', '2026-12-05')
        );
    }

    public function test_biweekly_keeps_its_two_week_rhythm(): void
    {
        $this->assertSame(
            ['2026-11-20', '2026-12-04', '2026-12-18'],
            $this->dates($this->salary(Frequency::Biweekly, day: null, weekday: 5, start: '2026-11-01'), '2026-11-19', '2026-12-31')
        );
    }

    public function test_weekly_rule_started_long_ago_still_hits_the_right_days(): void
    {
        // Fridays since 2024: 20 Nov 2026 must still be a Friday occurrence.
        $this->assertSame(
            ['2026-11-20', '2026-11-27'],
            $this->dates($this->salary(Frequency::Weekly, day: null, weekday: 5, start: '2024-01-01'), '2026-11-19', '2026-11-30')
        );
    }

    public function test_yearly_rule_on_its_month(): void
    {
        $this->assertSame(
            ['2027-03-15'],
            $this->dates($this->salary(Frequency::Yearly, day: 15, start: '2024-03-10'), '2026-11-19', '2027-04-30')
        );
    }

    public function test_a_monthly_rule_needs_a_day_of_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->salary(day: null);
    }

    public function test_a_weekly_rule_needs_a_weekday(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->salary(Frequency::Weekly, day: null, weekday: null);
    }

    public function test_a_rule_cannot_be_a_reserve(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecurringRule(
            'x', 'x', '100', Frequency::Monthly, 1, null, ForecastFixtures::d('2026-01-01'), null,
            DateRules::SHIFT_NONE, Direction::Reserve, EventKind::SavingsReserve
        );
    }
}