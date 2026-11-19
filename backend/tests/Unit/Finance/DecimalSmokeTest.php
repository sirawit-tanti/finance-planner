<?php

namespace Tests\Unit\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

/**
 * Setup check only (not business logic): proves brick/math is installed and
 * behaves as exact decimal arithmetic, unlike PHP floats.
 * Extends PHPUnit's TestCase (not Laravel's) on purpose: no framework, no DB.
 */
class DecimalSmokeTest extends TestCase
{
    public function test_float_arithmetic_is_not_exact_but_bigdecimal_is(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2); // the problem we are avoiding

        $sum = BigDecimal::of('0.1')->plus('0.2');
        $this->assertTrue($sum->isEqualTo('0.3'));
    }

    public function test_half_up_rounding_to_two_decimals(): void
    {
        $this->assertSame(
            '2665.46',
            (string) BigDecimal::of('2665.4637')->toScale(2, RoundingMode::HALF_UP)
        );
        $this->assertSame(
            '0.13',
            (string) BigDecimal::of('0.125')->toScale(2, RoundingMode::HALF_UP)
        );
    }
}