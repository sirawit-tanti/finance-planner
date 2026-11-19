<?php

declare(strict_types=1);

namespace App\Domain\Finance\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The ONLY place where rounding modes are chosen. Every financial calculation
 * goes through here, so a brick/math version change touches one file.
 * Never uses float.
 */
final class MoneyMath
{
    /** Scale of money values (satang). */
    public const MONEY_SCALE = 2;

    /** Scale used for intermediate calculations (rates, divisions, powers). */
    public const CALC_SCALE = 20;

    /** Round to 2 decimals, half-up (agreed rule from Phase 2). */
    public static function round2(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Accept an input only if it is representable with at most 2 decimals.
     * "10" => "10.00", "10.001" => exception (we never silently round user input).
     */
    public static function exact2(BigDecimal|string $value): BigDecimal
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of($value);

        try {
            return $decimal->toScale(self::MONEY_SCALE, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw new InvalidArgumentException("Money value has more than 2 decimal places: {$decimal}");
        }
    }

    /** Division at calculation scale (half-up). Throws on division by zero. */
    public static function div(BigDecimal|string|int $a, BigDecimal|string|int $b, int $scale = self::CALC_SCALE): BigDecimal
    {
        $numerator = $a instanceof BigDecimal ? $a : BigDecimal::of($a);

        return $numerator->dividedBy($b, $scale, RoundingMode::HalfUp);
    }

    /** "12" (percent) => 0.12 (fraction). */
    public static function percentToFraction(BigDecimal|string $percent): BigDecimal
    {
        return self::div($percent, 100);
    }

    /**
     * base^exponent for a non-negative integer exponent, rounding to the
     * calculation scale at each step so the number of digits never explodes.
     */
    public static function pow(BigDecimal $base, int $exponent): BigDecimal
    {
        if ($exponent < 0) {
            throw new InvalidArgumentException('Exponent must be >= 0.');
        }

        $result = BigDecimal::one();
        for ($i = 0; $i < $exponent; $i++) {
            $result = $result->multipliedBy($base)->toScale(self::CALC_SCALE, RoundingMode::HalfUp);
        }

        return $result;
    }

    /**
     * Split a money total into EXACTLY $parts values that sum to the total.
     * The first (parts - 1) values are round2(total / parts); the last value
     * is total minus the others, so it absorbs the rounding difference.
     *
     * @return list<BigDecimal>  always count == $parts
     */
    public static function splitEvenly(BigDecimal|string $total, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be >= 1.');
        }

        $total = self::exact2($total);
        $share = self::round2(self::div($total, $parts));

        // Fixed-size result: the row count can never differ from $parts.
        $result = array_fill(0, $parts, $share);
        $result[$parts - 1] = $total->minus($share->multipliedBy($parts - 1));

        return $result;
    }

    /** Round a NON-NEGATIVE amount down to a multiple of $step (e.g. 16,429.45 => 16,400.00 for 100). */
    public static function floorToMultiple(BigDecimal $value, int $step): BigDecimal
    {
        if ($step < 1 || $value->isNegative()) {
            throw new InvalidArgumentException('Step must be >= 1 and the value must not be negative.');
        }

        return $value->dividedBy($step, 0, RoundingMode::Down)
            ->multipliedBy($step)
            ->toScale(self::MONEY_SCALE);
    }

    /** ceil(a / b) as an integer, for positive values (e.g. months needed to reach a goal). */
    public static function ceilDiv(BigDecimal $a, BigDecimal $b): int
    {
        if (! $b->isPositive() || $a->isNegative()) {
            throw new InvalidArgumentException('Divisor must be positive and the dividend must not be negative.');
        }

        return $a->dividedBy($b, 0, RoundingMode::Ceiling)->toInt();
    }
}