<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Installments\FeeMode;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Installments\InstallmentMethod;
use App\Domain\Finance\Installments\InstallmentTerms;
use App\Domain\Finance\Installments\PayoffCalculator;
use App\Domain\Finance\Installments\Schedule;
use App\Domain\Finance\Installments\ScheduleBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PayoffCalculatorTest extends TestCase
{
    private function schedule(
        InstallmentMethod $method,
        string $principal,
        string $rate,
        int $terms,
        string $fee = '0',
        FeeMode $feeMode = FeeMode::None,
    ): Schedule {
        return (new ScheduleBuilder())->build(new InstallmentTerms(
            $method, $principal, $rate, $terms, Frequency::Monthly,
            new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'),
            $fee, $feeMode,
        ));
    }

    /** Flat 30,000 @ 12% for 12 months, first 3 installments already paid => rows 4..12 pending. */
    private function flatPending(): array
    {
        return array_slice($this->schedule(InstallmentMethod::Flat, '30000', '12', 12)->items, 3);
    }

    public function test_flat_payoff_without_any_discount_equals_the_remaining_schedule(): void
    {
        $quote = (new PayoffCalculator())->quote($this->flatPending());

        $this->assertSame('22500.00', (string) $quote->remainingPrincipal);  // 9 x 2,500
        $this->assertSame('2700.00', (string) $quote->remainingInterest);    // 9 x 300
        $this->assertSame('0.00', (string) $quote->remainingFees);
        $this->assertSame('25200.00', (string) $quote->scheduledRemaining);  // 9 x 2,800
        $this->assertSame('25200.00', (string) $quote->payoffAmount);        // no discount is assumed
        $this->assertSame(9, $quote->remainingInstallments);
    }

    public function test_user_entered_discount_and_early_fee_are_applied(): void
    {
        $quote = (new PayoffCalculator())->quote($this->flatPending(), '1000', '500');

        $this->assertSame('1000.00', (string) $quote->interestDiscount);
        $this->assertSame('500.00', (string) $quote->earlyFee);
        $this->assertSame('24700.00', (string) $quote->payoffAmount); // 25,200 - 1,000 + 500
    }

    public function test_a_discount_equal_to_all_remaining_interest_is_allowed(): void
    {
        $quote = (new PayoffCalculator())->quote($this->flatPending(), '2700');

        $this->assertSame('22500.00', (string) $quote->payoffAmount);
    }

    public function test_a_discount_above_the_remaining_interest_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PayoffCalculator())->quote($this->flatPending(), '2700.01');
    }

    public function test_negative_discount_or_fee_is_rejected(): void
    {
        foreach ([['-1', '0'], ['0', '-1']] as [$discount, $fee]) {
            try {
                (new PayoffCalculator())->quote($this->flatPending(), $discount, $fee);
                $this->fail("Expected an exception for discount {$discount}, fee {$fee}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_zero_percent_plan_cannot_have_an_interest_discount(): void
    {
        $pending = array_slice($this->schedule(InstallmentMethod::Zero, '12000', '0', 12)->items, 6);

        $this->expectException(InvalidArgumentException::class);
        (new PayoffCalculator())->quote($pending, '0.01');
    }

    public function test_spread_fees_are_part_of_the_payoff(): void
    {
        $pending = array_slice(
            $this->schedule(InstallmentMethod::Zero, '12000', '0', 12, '600', FeeMode::Spread)->items,
            6
        );

        $quote = (new PayoffCalculator())->quote($pending);

        $this->assertSame('6000.00', (string) $quote->remainingPrincipal);
        $this->assertSame('0.00', (string) $quote->remainingInterest);
        $this->assertSame('300.00', (string) $quote->remainingFees);   // 6 x 50
        $this->assertSame('6300.00', (string) $quote->payoffAmount);
        $this->assertSame(6, $quote->remainingInstallments);
    }

    public function test_an_unpaid_upfront_fee_row_counts_as_a_fee_but_not_as_an_installment(): void
    {
        $schedule = $this->schedule(InstallmentMethod::Zero, '12000', '0', 12, '600', FeeMode::Upfront);

        $quote = (new PayoffCalculator())->quote($schedule->items); // all 13 rows pending

        $this->assertSame('600.00', (string) $quote->remainingFees);
        $this->assertSame('12600.00', (string) $quote->payoffAmount);
        $this->assertSame(12, $quote->remainingInstallments);
    }

    public function test_remaining_principal_matches_the_schedule_balance_for_reducing_loans(): void
    {
        $schedule = $this->schedule(InstallmentMethod::Reducing, '30000', '12', 12);

        // Two installments paid => balance after row 2 must equal the principal still to be paid.
        $quote = (new PayoffCalculator())->quote(array_slice($schedule->items, 2));

        $this->assertSame((string) $schedule->items[1]->remainingPrincipalAfter, (string) $quote->remainingPrincipal);
        $this->assertSame(10, $quote->remainingInstallments);
    }

    public function test_nothing_pending_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PayoffCalculator())->quote([]);
    }
}