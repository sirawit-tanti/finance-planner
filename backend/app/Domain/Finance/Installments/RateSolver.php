<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Rate conversions that need iteration. brick/math has no fractional powers
 * (e.g. (1+EIR)^(1/12)), so we solve them numerically at 20 decimal places.
 */
final class RateSolver
{
    private const TOLERANCE = '0.000000000000000001'; // 1e-18
    private const MAX_ITERATIONS = 100;

    /**
     * Effective annual rate (percent) => rate per period (fraction).
     * Solves (1 + r)^m = 1 + EIR with Newton's method.
     */
    public static function periodicFromEffective(BigDecimal|string $effectiveAnnualPercent, int $periodsPerYear): BigDecimal
    {
        if ($periodsPerYear < 1) {
            throw new InvalidArgumentException('Periods per year must be >= 1.');
        }

        $effective = MoneyMath::percentToFraction($effectiveAnnualPercent);
        if ($effective->isNegative()) {
            throw new InvalidArgumentException('Effective rate must not be negative.');
        }
        if ($effective->isZero()) {
            return BigDecimal::zero()->toScale(MoneyMath::CALC_SCALE);
        }

        $target = BigDecimal::one()->plus($effective);
        // EIR / m is always >= the true root, and f is convex, so Newton converges monotonically.
        $rate = MoneyMath::div($effective, $periodsPerYear);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $onePlusRate = BigDecimal::one()->plus($rate);
            $powerMinusOne = MoneyMath::pow($onePlusRate, $periodsPerYear - 1);
            $f = $powerMinusOne->multipliedBy($onePlusRate)->minus($target);
            $derivative = $powerMinusOne->multipliedBy($periodsPerYear);

            $next = $rate->minus(MoneyMath::div($f, $derivative));
            $delta = $next->minus($rate)->abs();
            $rate = $next;

            if ($delta->isLessThan(self::TOLERANCE)) {
                break;
            }
        }

        return $rate->toScale(MoneyMath::CALC_SCALE, RoundingMode::HALF_UP);
    }

    /** Rate per period (fraction) => effective annual rate (fraction): (1 + r)^m - 1. */
    public static function effectiveFromPeriodic(BigDecimal $periodicRate, int $periodsPerYear): BigDecimal
    {
        return MoneyMath::pow(BigDecimal::one()->plus($periodicRate), $periodsPerYear)->minus(1);
    }

    /**
     * Real rate per period (fraction) of a stream of equal-spaced payments (IRR),
     * found by bisection: the r where the present value of the payments equals
     * the money actually received now.
     *
     * Used to show the TRUE effective cost of a flat-rate deal (mode (b) in Phase 2).
     * Pass the net proceeds (e.g. price minus an upfront fee, if the fee is paid at the start).
     *
     * @param list<BigDecimal> $payments payment at t = 1, 2, 3, ...
     */
    public static function irr(BigDecimal $netProceeds, array $payments): BigDecimal
    {
        if (! $netProceeds->isPositive() || $payments === []) {
            throw new InvalidArgumentException('Need a positive amount received and at least one payment.');
        }

        $totalPaid = BigDecimal::zero();
        foreach ($payments as $payment) {
            $totalPaid = $totalPaid->plus($payment);
        }

        $comparison = $totalPaid->compareTo($netProceeds);
        if ($comparison < 0) {
            throw new InvalidArgumentException('Total payments are lower than the amount received: no non-negative rate exists.');
        }
        if ($comparison === 0) {
            return BigDecimal::zero()->toScale(MoneyMath::CALC_SCALE);
        }

        $low = BigDecimal::zero()->toScale(MoneyMath::CALC_SCALE);
        $high = BigDecimal::one()->toScale(MoneyMath::CALC_SCALE); // 100% per period is far beyond any real case

        if (self::presentValue($payments, $high)->isGreaterThan($netProceeds)) {
            throw new InvalidArgumentException('Rate per period would exceed 100%.');
        }

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $mid = MoneyMath::div($low->plus($high), 2);

            // Present value falls as the rate rises.
            if (self::presentValue($payments, $mid)->isGreaterThan($netProceeds)) {
                $low = $mid;
            } else {
                $high = $mid;
            }

            if ($high->minus($low)->isLessThan(self::TOLERANCE)) {
                break;
            }
        }

        return MoneyMath::div($low->plus($high), 2);
    }

    /** @param list<BigDecimal> $payments */
    private static function presentValue(array $payments, BigDecimal $rate): BigDecimal
    {
        $onePlusRate = BigDecimal::one()->plus($rate);
        $discount = BigDecimal::one()->toScale(MoneyMath::CALC_SCALE);
        $present = BigDecimal::zero();

        foreach ($payments as $payment) {
            $discount = MoneyMath::div($discount, $onePlusRate);
            $present = $present->plus($payment->multipliedBy($discount));
        }

        return $present;
    }
}