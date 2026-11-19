<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Early-payoff quote.
 *
 *   payoff = remaining principal + remaining scheduled interest + remaining fees
 *            - interest discount + early fee
 *
 * The interest discount and early fee come from the user (the lender's real quote)
 * and default to 0: we never assume a lender waives interest.
 * Partial prepayment is NOT calculated here: how it changes a schedule depends on the contract.
 */
final class PayoffCalculator
{
    /** @param list<ScheduleItem> $pendingItems rows not yet paid (the caller filters by status) */
    public function quote(
        array $pendingItems,
        BigDecimal|string $interestDiscount = '0',
        BigDecimal|string $earlyFee = '0',
    ): PayoffQuote {
        if ($pendingItems === []) {
            throw new InvalidArgumentException('There are no pending installments to pay off.');
        }

        $principal = BigDecimal::of('0.00');
        $interest = BigDecimal::of('0.00');
        $fees = BigDecimal::of('0.00');
        $count = 0;

        foreach ($pendingItems as $item) {
            $principal = $principal->plus($item->principalPart);
            $interest = $interest->plus($item->interestPart);
            $fees = $fees->plus($item->feePart);
            if ($item->installmentNo >= 1) {
                $count++;
            }
        }

        $discount = MoneyMath::exact2($interestDiscount);
        $fee = MoneyMath::exact2($earlyFee);

        if ($discount->isNegative() || $fee->isNegative()) {
            throw new InvalidArgumentException('Discount and early fee must not be negative.');
        }
        if ($discount->isGreaterThan($interest)) {
            throw new InvalidArgumentException("Interest discount {$discount} is more than the remaining interest {$interest}.");
        }

        $scheduled = $principal->plus($interest)->plus($fees);

        return new PayoffQuote(
            $principal,
            $interest,
            $fees,
            $scheduled,
            $discount,
            $fee,
            $scheduled->minus($discount)->plus($fee),
            $count,
        );
    }
}