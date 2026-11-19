<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyMathTest extends TestCase
{
    public function test_round2_rounds_half_up(): void
    {
        $this->assertSame('0.13', (string) MoneyMath::round2(BigDecimal::of('0.125')));
        $this->assertSame('0.12', (string) MoneyMath::round2(BigDecimal::of('0.124')));
        $this->assertSame('2665.46', (string) MoneyMath::round2(BigDecimal::of('2665.4637')));
    }

    public function test_exact2_pads_to_two_decimals(): void
    {
        $this->assertSame('10.00', (string) MoneyMath::exact2('10'));
        $this->assertSame('10.50', (string) MoneyMath::exact2('10.5'));
    }

    public function test_exact2_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyMath::exact2('10.001');
    }

    public function test_split_evenly_last_part_absorbs_the_remainder(): void
    {
        $parts = array_map('strval', MoneyMath::splitEvenly('100.00', 3));
        $this->assertSame(['33.33', '33.33', '33.34'], $parts);
    }

    public function test_split_evenly_always_sums_to_the_total(): void
    {
        foreach ([['1000.00', 7], ['0.05', 3], ['99999.99', 12], ['10.00', 1]] as [$total, $parts]) {
            $sum = BigDecimal::of('0.00');
            foreach (MoneyMath::splitEvenly($total, $parts) as $part) {
                $sum = $sum->plus($part);
            }
            $this->assertSame($total, (string) $sum, "split of {$total} into {$parts}");
        }
    }
    
    public function test_split_evenly_returns_exactly_the_requested_number_of_parts(): void
    {
        foreach ([1, 2, 3, 7, 12, 360] as $parts) {
            $this->assertCount($parts, MoneyMath::splitEvenly('100.00', $parts), "parts = {$parts}");
        }
    }

    public function test_pow_matches_known_value(): void
    {
        $value = MoneyMath::pow(BigDecimal::of('1.01'), 12);
        $diff = $value->minus('1.126825030131969720661201')->abs();
        $this->assertTrue($diff->isLessThan('0.000000000000000001'));
    }

    public function test_div_by_zero_throws(): void
    {
        $this->expectException(\Brick\Math\Exception\DivisionByZeroException::class);
        MoneyMath::div('1', '0');
    }
}