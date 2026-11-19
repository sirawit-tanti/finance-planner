<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Installments\RateSolver;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RateSolverTest extends TestCase
{
    // 1.01^12 - 1 = 12.6825030131969720661201%
    private const EIR_OF_ONE_PERCENT_MONTHLY = '12.6825030131969720661201';

    public function test_effective_annual_rate_converts_to_one_percent_monthly(): void
    {
        $r = RateSolver::periodicFromEffective(self::EIR_OF_ONE_PERCENT_MONTHLY, 12);
        $this->assertTrue($r->minus('0.01')->abs()->isLessThan('0.000000000000001'));
    }

    public function test_zero_effective_rate_gives_zero(): void
    {
        $this->assertTrue(RateSolver::periodicFromEffective('0', 12)->isZero());
    }

    public function test_one_period_per_year_is_the_rate_itself(): void
    {
        $r = RateSolver::periodicFromEffective('8', 1);
        $this->assertTrue($r->minus('0.08')->abs()->isLessThan('0.000000000000001'));
    }

    public function test_effective_from_periodic(): void
    {
        $e = RateSolver::effectiveFromPeriodic(BigDecimal::of('0.01'), 12);
        $this->assertTrue($e->minus('0.126825030131969720661201')->abs()->isLessThan('0.000000000000001'));
    }

    public function test_irr_of_a_level_payment_loan_is_the_periodic_rate(): void
    {
        $payments = array_fill(0, 12, BigDecimal::of('2665.46'));
        $r = RateSolver::irr(BigDecimal::of('30000.00'), $payments);
        $this->assertTrue($r->minus('0.01')->abs()->isLessThan('0.000001'));
    }

    public function test_irr_reveals_the_true_cost_of_a_flat_rate(): void
    {
        // Flat 12% on 30,000 over 12 months => 2,800 per month, far costlier than "12%".
        $payments = array_fill(0, 12, BigDecimal::of('2800.00'));
        $monthly = RateSolver::irr(BigDecimal::of('30000.00'), $payments);

        $this->assertTrue($monthly->isGreaterThan('0.017'));
        $this->assertTrue($monthly->isLessThan('0.019'));

        $annual = RateSolver::effectiveFromPeriodic($monthly, 12);
        $this->assertTrue($annual->isGreaterThan('0.20'));
        $this->assertTrue($annual->isLessThan('0.26'));
    }

    public function test_irr_is_zero_when_payments_equal_the_amount_received(): void
    {
        $payments = array_fill(0, 4, BigDecimal::of('250.00'));
        $this->assertTrue(RateSolver::irr(BigDecimal::of('1000.00'), $payments)->isZero());
    }

    public function test_irr_throws_when_payments_are_below_the_amount_received(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RateSolver::irr(BigDecimal::of('1000.00'), array_fill(0, 4, BigDecimal::of('200.00')));
    }
}