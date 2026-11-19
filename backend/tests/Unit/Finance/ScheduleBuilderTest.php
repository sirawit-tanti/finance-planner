<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Installments\FeeMode;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Installments\InstallmentMethod;
use App\Domain\Finance\Installments\InstallmentTerms;
use App\Domain\Finance\Installments\Schedule;
use App\Domain\Finance\Installments\ScheduleBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScheduleBuilderTest extends TestCase
{
    private function terms(
        InstallmentMethod $method,
        string $principal,
        string $ratePercent,
        int $terms,
        string $fee = '0',
        FeeMode $feeMode = FeeMode::None,
        Frequency $frequency = Frequency::Monthly,
        string $start = '2026-01-01',
        string $firstDue = '2026-02-01',
    ): InstallmentTerms {
        return new InstallmentTerms(
            $method,
            $principal,
            $ratePercent,
            $terms,
            $frequency,
            new DateTimeImmutable($start),
            new DateTimeImmutable($firstDue),
            $fee,
            $feeMode,
        );
    }

    private function build(InstallmentTerms $terms): Schedule
    {
        return (new ScheduleBuilder())->build($terms);
    }

    // ---------- 0% ----------

    public function test_zero_percent_24000_over_10_months(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Zero, '24000', '0', 10));

        $this->assertCount(10, $schedule->items);
        foreach ($schedule->items as $item) {
            $this->assertSame('2400.00', (string) $item->totalDue);
            $this->assertSame('0.00', (string) $item->interestPart);
        }
        $this->assertSame('0.00', (string) $schedule->items[9]->remainingPrincipalAfter);
        $this->assertSame('24000.00', (string) $schedule->totalPayable());
    }

    public function test_zero_percent_with_remainder_puts_it_on_the_last_installment(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Zero, '10000', '0', 3));
        $this->assertSame(
            ['3333.33', '3333.33', '3333.34'],
            array_map(fn ($i) => (string) $i->totalDue, $schedule->items)
        );
    }

    // ---------- Flat ----------

    public function test_flat_30000_at_12_percent_for_12_months(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Flat, '30000', '12', 12));

        $this->assertCount(12, $schedule->items);
        foreach ($schedule->items as $item) {
            $this->assertSame('2500.00', (string) $item->principalPart);
            $this->assertSame('300.00', (string) $item->interestPart);
            $this->assertSame('2800.00', (string) $item->totalDue);
        }
        $this->assertSame('27500.00', (string) $schedule->items[0]->remainingPrincipalAfter);
        $this->assertSame('0.00', (string) $schedule->items[11]->remainingPrincipalAfter);
        $this->assertSame('3600.00', (string) $schedule->totalInterest());
        $this->assertSame('33600.00', (string) $schedule->totalPayable());
    }

    public function test_flat_remainders_land_on_the_last_installment(): void
    {
        // interest = 1000 x 10% x 3/12 = 25.00
        $schedule = $this->build($this->terms(InstallmentMethod::Flat, '1000', '10', 3));

        $this->assertSame(
            ['333.33', '333.33', '333.34'],
            array_map(fn ($i) => (string) $i->principalPart, $schedule->items)
        );
        $this->assertSame(
            ['8.33', '8.33', '8.34'],
            array_map(fn ($i) => (string) $i->interestPart, $schedule->items)
        );
        $this->assertSame('25.00', (string) $schedule->totalInterest());
        $this->assertSame('1025.00', (string) $schedule->totalPayable());
    }

    // ---------- Reducing balance ----------

    public function test_reducing_30000_at_12_percent_for_12_months_first_rows(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Reducing, '30000', '12', 12));
        [$i1, $i2, $i3] = $schedule->items;

        $this->assertSame('300.00', (string) $i1->interestPart);
        $this->assertSame('2365.46', (string) $i1->principalPart);
        $this->assertSame('2665.46', (string) $i1->totalDue);
        $this->assertSame('27634.54', (string) $i1->remainingPrincipalAfter);

        $this->assertSame('276.35', (string) $i2->interestPart);
        $this->assertSame('2389.11', (string) $i2->principalPart);
        $this->assertSame('25245.43', (string) $i2->remainingPrincipalAfter);

        $this->assertSame('252.45', (string) $i3->interestPart);
        $this->assertSame('2413.01', (string) $i3->principalPart);
        $this->assertSame('22832.42', (string) $i3->remainingPrincipalAfter);
    }

    public function test_reducing_totals_match_the_contract(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Reducing, '30000', '12', 12));

        $this->assertSame('30000.00', (string) $schedule->totalPrincipal());
        $this->assertSame('0.00', (string) $schedule->items[11]->remainingPrincipalAfter);

        // Exact (unrounded) PMT is 2665.4637 => total interest 1985.5644 and last payment ~2665.46.
        $this->assertTrue($schedule->totalInterest()->minus('1985.56')->abs()->isLessThanOrEqualTo('0.15'));
        $this->assertTrue($schedule->items[11]->totalDue->minus('2665.46')->abs()->isLessThanOrEqualTo('0.15'));
    }

    public function test_reducing_at_zero_rate_behaves_like_zero_percent(): void
    {
        $schedule = $this->build($this->terms(InstallmentMethod::Reducing, '12000', '0', 12));

        foreach ($schedule->items as $item) {
            $this->assertSame('1000.00', (string) $item->totalDue);
            $this->assertSame('0.00', (string) $item->interestPart);
        }
    }

    // ---------- Effective ----------

    public function test_effective_rate_equivalent_to_one_percent_monthly(): void
    {
        // EIR 12.6825030131969720661201% per year == 1% per month
        $schedule = $this->build($this->terms(InstallmentMethod::Effective, '30000', '12.6825030131969720661201', 12));
        $first = $schedule->items[0];

        $this->assertSame('300.00', (string) $first->interestPart);
        $this->assertSame('2665.46', (string) $first->totalDue);
        $this->assertSame('0.00', (string) $schedule->items[11]->remainingPrincipalAfter);
    }

    // ---------- Fees ----------

    public function test_upfront_fee_creates_installment_zero(): void
    {
        $schedule = $this->build(
            $this->terms(InstallmentMethod::Zero, '12000', '0', 12, '600', FeeMode::Upfront)
        );

        $this->assertCount(13, $schedule->items);
        $fee = $schedule->items[0];
        $this->assertSame(0, $fee->installmentNo);
        $this->assertSame('2026-01-01', $fee->dueDate->format('Y-m-d'));
        $this->assertSame('600.00', (string) $fee->totalDue);
        $this->assertSame('12000.00', (string) $fee->remainingPrincipalAfter);
        $this->assertSame('1000.00', (string) $schedule->items[1]->totalDue);
        $this->assertSame('12600.00', (string) $schedule->totalPayable());
        $this->assertCount(12, $schedule->installmentAmounts());
    }

    public function test_spread_fee_is_divided_across_installments(): void
    {
        $schedule = $this->build(
            $this->terms(InstallmentMethod::Zero, '12000', '0', 12, '600', FeeMode::Spread)
        );

        $this->assertCount(12, $schedule->items);
        foreach ($schedule->items as $item) {
            $this->assertSame('50.00', (string) $item->feePart);
            $this->assertSame('1050.00', (string) $item->totalDue);
        }
        $this->assertSame('600.00', (string) $schedule->totalFees());
    }

    // ---------- Due dates ----------

    public function test_monthly_due_dates_clamp_to_month_end_but_keep_anchor_day(): void
    {
        $schedule = $this->build(
            $this->terms(InstallmentMethod::Zero, '3000', '0', 3, firstDue: '2026-01-31')
        );

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            array_map(fn ($i) => $i->dueDate->format('Y-m-d'), $schedule->items)
        );
    }

    public function test_weekly_due_dates(): void
    {
        $schedule = $this->build($this->terms(
            InstallmentMethod::Zero, '300', '0', 3,
            frequency: Frequency::Weekly, start: '2026-11-18', firstDue: '2026-11-20'
        ));

        $this->assertSame(
            ['2026-11-20', '2026-11-27', '2026-12-04'],
            array_map(fn ($i) => $i->dueDate->format('Y-m-d'), $schedule->items)
        );
    }

    // ---------- Validation (no guessing of missing conditions) ----------

    public function test_zero_method_with_a_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms(InstallmentMethod::Zero, '1000', '5', 10);
    }

    public function test_fee_without_a_fee_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms(InstallmentMethod::Zero, '1000', '0', 10, '100', FeeMode::None);
    }

    public function test_invalid_term_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms(InstallmentMethod::Zero, '1000', '0', 0);
    }

    public function test_negative_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms(InstallmentMethod::Flat, '1000', '-1', 10);
    }

    public function test_first_due_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms(InstallmentMethod::Zero, '1000', '0', 10, start: '2026-02-01', firstDue: '2026-01-01');
    }
}