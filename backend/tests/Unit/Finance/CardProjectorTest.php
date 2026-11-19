<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\CardCharge;
use App\Domain\Finance\Forecast\CardProjector;
use App\Domain\Finance\Forecast\KnownStatement;
use PHPUnit\Framework\TestCase;

class CardProjectorTest extends TestCase
{
    /** @param list<KnownStatement> $known @param list<CardCharge> $charges @return list<array{0: string, 1: string}> */
    private function project(array $known, array $charges, string $to = '2027-02-28'): array
    {
        $events = (new CardProjector())->project(
            'card-a', 'บัตร A', 5, 25, $known, $charges, ForecastFixtures::d('2026-11-19'), ForecastFixtures::d($to)
        );

        return array_map(fn ($e) => [$e->date->format('Y-m-d'), (string) $e->amount], $events);
    }

    private function charge(string $date, string $amount): CardCharge
    {
        return new CardCharge(ForecastFixtures::d($date), $amount, 'ผ่อน', 'installment_plan', 'plan-1');
    }

    public function test_one_payment_per_statement_on_its_due_date(): void
    {
        $result = $this->project(
            [new KnownStatement(ForecastFixtures::d('2026-11-05'), ForecastFixtures::d('2026-11-25'), '3000')],
            [
                $this->charge('2026-11-10', '900'),   // before the start: already inside a real statement, ignored
                $this->charge('2026-11-20', '1000'),  // after the 5th => December statement
                $this->charge('2026-12-01', '500'),   // same cycle
                $this->charge('2026-12-10', '700'),   // => January statement
            ]
        );

        $this->assertSame(
            [['2026-11-25', '3000.00'], ['2026-12-25', '1500.00'], ['2027-01-25', '700.00']],
            $result
        );
    }

    public function test_known_outstanding_and_future_charges_of_the_same_statement_are_merged(): void
    {
        $result = $this->project(
            [new KnownStatement(ForecastFixtures::d('2026-12-05'), ForecastFixtures::d('2026-12-25'), '200')],
            [$this->charge('2026-11-20', '1000')]
        );

        $this->assertSame([['2026-12-25', '1200.00']], $result);
    }

    public function test_an_overdue_statement_is_due_on_the_forecast_start(): void
    {
        $events = (new CardProjector())->project(
            'card-a', 'บัตร A', 5, 25,
            [new KnownStatement(ForecastFixtures::d('2026-10-05'), ForecastFixtures::d('2026-11-10'), '800')],
            [], ForecastFixtures::d('2026-11-19'), ForecastFixtures::d('2027-02-28')
        );

        $this->assertCount(1, $events);
        $this->assertSame('2026-11-19', $events[0]->date->format('Y-m-d'));
        $this->assertStringContainsString('เลยกำหนด', $events[0]->label);
    }

    public function test_a_fully_paid_statement_creates_no_event(): void
    {
        $this->assertSame(
            [],
            $this->project([new KnownStatement(ForecastFixtures::d('2026-11-05'), ForecastFixtures::d('2026-11-25'), '0')], [])
        );
    }

    public function test_payments_after_the_horizon_are_left_out(): void
    {
        $result = $this->project([], [$this->charge('2026-12-10', '700')], '2026-12-31');

        $this->assertSame([], $result); // due 25 Jan 2027 is outside the window
    }

    public function test_the_event_is_a_card_payment_from_the_card(): void
    {
        $events = (new CardProjector())->project(
            'card-a', 'บัตร A', 5, 25, [], [$this->charge('2026-11-20', '1000')],
            ForecastFixtures::d('2026-11-19'), ForecastFixtures::d('2027-02-28')
        );

        $this->assertSame('credit_card', $events[0]->sourceType);
        $this->assertSame('card-a', $events[0]->sourceId);
        $this->assertSame('card_payment', $events[0]->kind->value);
    }
}