<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\InstallmentEventGenerator;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Installments\InstallmentMethod;
use App\Domain\Finance\Installments\InstallmentTerms;
use App\Domain\Finance\Installments\PayoffCalculator;
use App\Domain\Finance\Installments\ScheduleBuilder;
use PHPUnit\Framework\TestCase;

class InstallmentEventGeneratorTest extends TestCase
{
    /** Flat 30,000 @ 12% x 12, first due 2026-12-10 => rows 2,800 on the 10th of Dec 2026 ... Nov 2027. */
    private function items(): array
    {
        return (new ScheduleBuilder())->build(new InstallmentTerms(
            InstallmentMethod::Flat, '30000', '12', 12, Frequency::Monthly,
            ForecastFixtures::d('2026-11-01'), ForecastFixtures::d('2026-12-10'),
        ))->items;
    }

    public function test_account_events_keep_principal_and_interest_apart(): void
    {
        $events = (new InstallmentEventGenerator())->accountEvents(
            'plan-1', 'ผ่อนโทรศัพท์', $this->items(),
            ForecastFixtures::d('2026-11-19'), ForecastFixtures::d('2027-02-28')
        );

        $this->assertCount(3, $events); // 10 Dec, 10 Jan, 10 Feb
        $this->assertSame('2800.00', (string) $events[0]->amount);
        $this->assertSame('2500.00', (string) $events[0]->principalPart);
        $this->assertSame('300.00', (string) $events[0]->interestPart);
        $this->assertSame('installment_plan', $events[0]->sourceType);
    }

    public function test_an_overdue_row_is_placed_on_the_forecast_start(): void
    {
        $events = (new InstallmentEventGenerator())->accountEvents(
            'plan-1', 'ผ่อนโทรศัพท์', $this->items(),
            ForecastFixtures::d('2026-12-20'), ForecastFixtures::d('2027-01-31')
        );

        $this->assertSame('2026-12-20', $events[0]->date->format('Y-m-d'));
        $this->assertStringContainsString('เลยกำหนด', $events[0]->label);
        $this->assertSame('2027-01-10', $events[1]->date->format('Y-m-d'));
    }

    public function test_card_charges_use_the_row_due_dates(): void
    {
        $charges = (new InstallmentEventGenerator())->cardCharges('plan-1', 'ผ่อนโทรศัพท์', $this->items());

        $this->assertCount(12, $charges);
        $this->assertSame('2026-12-10', $charges[0]->date->format('Y-m-d'));
        $this->assertSame('2800.00', (string) $charges[0]->amount);
    }

    public function test_the_payoff_event_adds_up_to_the_quote(): void
    {
        $pending = array_slice($this->items(), 3); // 9 rows left
        $quote = (new PayoffCalculator())->quote($pending, '1000', '500');

        $event = (new InstallmentEventGenerator())->payoffEvent('plan-1', 'ผ่อนโทรศัพท์', ForecastFixtures::d('2027-03-01'), $quote);

        $this->assertSame('24700.00', (string) $event->amount);
        $this->assertSame('22500.00', (string) $event->principalPart);
        $this->assertSame('1700.00', (string) $event->interestPart); // 2,700 - 1,000
        $this->assertSame('500.00', (string) $event->feePart);
        $this->assertSame(
            (string) $event->amount,
            (string) $event->principalPart->plus($event->interestPart)->plus($event->feePart)
        );
    }
}