<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\MonthForecast;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ForecastEngineTest extends TestCase
{
    public function test_november_is_a_partial_month_with_correct_totals(): void
    {
        $nov = ForecastFixtures::run(ForecastFixtures::baseEvents())->months[0];

        $this->assertSame('2026-11', $nov->key);
        $this->assertTrue($nov->isPartial);
        $this->assertSame('10000.00', (string) $nov->openingCash);
        $this->assertSame('32000.00', (string) $nov->incomeRecurring);
        $this->assertSame('7700.00', (string) $nov->expenseRecurring);
        $this->assertSame('5000.00', (string) $nov->cardPayments);
        $this->assertSame('32000.00', (string) $nov->totalIn);
        $this->assertSame('12700.00', (string) $nov->totalOut);
        $this->assertSame('29300.00', (string) $nov->closingCash);
        $this->assertCount(3, $nov->events);   // the event before the start is ignored
    }

    public function test_lowest_balance_is_found_between_the_events(): void
    {
        $nov = ForecastFixtures::run(ForecastFixtures::baseEvents())->months[0];

        $this->assertSame('2300.00', (string) $nov->minBalance);       // after the 7,700 payment, before the salary
        $this->assertSame('2026-11-20', $nov->minBalanceDate->format('Y-m-d'));
        $this->assertSame(MonthForecast::RISK_LOW, $nov->risk);        // 2,300 < emergency fund 3,000
    }

    public function test_december_has_installment_split_savings_reserve_and_free_cash(): void
    {
        $dec = ForecastFixtures::run(ForecastFixtures::baseEvents())->months[1];

        $this->assertFalse($dec->isPartial);
        $this->assertSame('29300.00', (string) $dec->openingCash);
        $this->assertSame('2800.00', (string) $dec->installmentsTotal);
        $this->assertSame('2500.00', (string) $dec->installmentPrincipal);
        $this->assertSame('300.00', (string) $dec->installmentInterest);
        $this->assertSame('0.00', (string) $dec->installmentFees);
        $this->assertSame('5000.00', (string) $dec->savingsReserved);
        $this->assertSame('45800.00', (string) $dec->closingCash);     // reserve does NOT reduce cash
        $this->assertSame('18800.00', (string) $dec->minBalance);
        $this->assertSame('5000.00', (string) $dec->reservedCumulative);
        $this->assertSame('37800.00', (string) $dec->freeCash);        // 45,800 - 5,000 - 3,000
        $this->assertSame(MonthForecast::RISK_OK, $dec->risk);
    }

    public function test_january_carries_the_balance_and_the_reserve_forward(): void
    {
        $jan = ForecastFixtures::run(ForecastFixtures::baseEvents())->months[2];

        $this->assertSame('45800.00', (string) $jan->openingCash);
        $this->assertSame('70100.00', (string) $jan->closingCash);
        $this->assertSame('38100.00', (string) $jan->minBalance);
        $this->assertSame('5000.00', (string) $jan->reservedCumulative);
        $this->assertSame('62100.00', (string) $jan->freeCash);
        $this->assertCount(2, $jan->events);                           // the event after the horizon is ignored
    }

    public function test_result_level_helpers(): void
    {
        $result = ForecastFixtures::run(ForecastFixtures::baseEvents());

        $this->assertSame('29300.00', (string) $result->closingAfter(1));
        $this->assertSame('70100.00', (string) $result->closingAfter(3));
        $this->assertSame('70100.00', (string) $result->finalClosing());
        $this->assertSame('2300.00', (string) $result->lowestBalance());
        $this->assertSame('300.00', (string) $result->totalInterest());
        $this->assertSame('2800.00', (string) $result->totalInstallments());
        $this->assertSame(['2026-11'], array_map(fn ($m) => $m->key, $result->riskMonths()));
        $this->assertSame('2800.00', (string) $result->month('2026-12')->installmentsTotal);
        $this->assertNull($result->month('1999-01'));
    }

    public function test_a_negative_balance_between_events_is_flagged(): void
    {
        $events = [
            ForecastFixtures::event('2026-11-20', '5000', Direction::Out, EventKind::ExpensePlanned),
            ForecastFixtures::event('2026-11-25', '6000', Direction::In, EventKind::IncomePlanned),
        ];
        $nov = ForecastFixtures::run($events, 1, '1000', '0')->months[0];

        $this->assertSame('2000.00', (string) $nov->closingCash);      // ends positive...
        $this->assertSame('-4000.00', (string) $nov->minBalance);      // ...but dips negative first
        $this->assertSame(MonthForecast::RISK_NEGATIVE, $nov->risk);
    }

    public function test_money_in_is_counted_before_money_out_on_the_same_day(): void
    {
        $events = [
            ForecastFixtures::event('2026-11-25', '5000', Direction::Out, EventKind::ExpensePlanned),
            ForecastFixtures::event('2026-11-25', '5000', Direction::In, EventKind::IncomePlanned),
        ];
        $nov = ForecastFixtures::run($events, 1, '0', '0')->months[0];

        $this->assertSame('0.00', (string) $nov->minBalance);
        $this->assertSame(MonthForecast::RISK_OK, $nov->risk);
    }

    public function test_an_installment_event_without_a_breakdown_counts_as_principal(): void
    {
        $events = [ForecastFixtures::event('2026-11-25', '1000', Direction::Out, EventKind::Installment)];
        $nov = ForecastFixtures::run($events, 1, '5000', '0')->months[0];

        $this->assertSame('1000.00', (string) $nov->installmentPrincipal);
        $this->assertSame('0.00', (string) $nov->installmentInterest);
    }

    public function test_financial_months_starting_on_the_25th(): void
    {
        $events = [
            ForecastFixtures::event('2026-11-24', '100', Direction::Out, EventKind::ExpensePlanned), // still period 2026-10
            ForecastFixtures::event('2026-11-25', '200', Direction::Out, EventKind::ExpensePlanned), // period 2026-11
        ];
        $result = ForecastFixtures::run($events, 2, '1000', '0', '2026-11-19', 25);

        $this->assertSame('2026-10', $result->months[0]->key);
        $this->assertSame('900.00', (string) $result->months[0]->closingCash);
        $this->assertSame('700.00', (string) $result->months[1]->closingCash);
    }

    public function test_reserved_savings_already_set_aside_reduce_free_cash(): void
    {
        $engine = new \App\Domain\Finance\Forecast\ForecastEngine();
        $result = $engine->run(
            ForecastFixtures::d('2026-11-19'), 1, new \App\Domain\Finance\Forecast\PeriodCalendar(1),
            '10000', [], '1000', '2500'
        );

        $this->assertSame('6500.00', (string) $result->months[0]->freeCash); // 10,000 - 2,500 - 1,000
    }

    public function test_the_same_input_always_gives_the_same_result(): void
    {
        $a = ForecastFixtures::run(ForecastFixtures::baseEvents());
        $b = ForecastFixtures::run(array_reverse(ForecastFixtures::baseEvents()));

        $this->assertSame((string) $a->finalClosing(), (string) $b->finalClosing());
        $this->assertSame((string) $a->months[1]->minBalance, (string) $b->months[1]->minBalance);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ForecastFixtures::run([], 37);
    }

    public function test_a_zero_amount_event_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CashEvent(ForecastFixtures::d('2026-11-20'), '0', Direction::Out, EventKind::ExpensePlanned, 'x');
    }
}