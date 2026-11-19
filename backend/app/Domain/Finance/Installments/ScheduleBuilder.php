<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use LogicException;

/**
 * Builds the full installment schedule. Pure function: no DB, no framework.
 *
 * Rules:
 *  - interest is charged on the financed amount only; fees never earn interest
 *  - every amount is rounded half-up to 2 decimals per row
 *  - the LAST row absorbs rounding so totals match the contract exactly
 */
final class ScheduleBuilder
{
    public function build(InstallmentTerms $terms): Schedule
    {
        $n = $terms->termCount;
        $perYear = $terms->frequency->periodsPerYear();

        [$principals, $interests] = match ($terms->method) {
            InstallmentMethod::Zero => $this->zeroSplit($terms->financedAmount, $n),
            InstallmentMethod::Flat => $this->flatSplit($terms, $perYear),
            InstallmentMethod::Reducing => $this->reducingSplit(
                $terms->financedAmount,
                $n,
                MoneyMath::div(MoneyMath::percentToFraction($terms->annualRatePercent), $perYear)
            ),
            InstallmentMethod::Effective => $this->reducingSplit(
                $terms->financedAmount,
                $n,
                RateSolver::periodicFromEffective($terms->annualRatePercent, $perYear)
            ),
        };

        $zero = BigDecimal::of('0.00');
        $fees = $terms->feeMode === FeeMode::Spread
            ? MoneyMath::splitEvenly($terms->feeAmount, $n)
            : array_fill(0, $n, $zero);

        $items = [];
        $balance = $terms->financedAmount;

        if ($terms->feeMode === FeeMode::Upfront && $terms->feeAmount->isPositive()) {
            $items[] = new ScheduleItem(0, $terms->startDate, $zero, $zero, $terms->feeAmount, $terms->feeAmount, $balance);
        }

        for ($i = 0; $i < $n; $i++) {
            $balance = $balance->minus($principals[$i]);
            $items[] = new ScheduleItem(
                $i + 1,
                $terms->frequency->dueDate($terms->firstDueDate, $i),
                $principals[$i],
                $interests[$i],
                $fees[$i],
                $principals[$i]->plus($interests[$i])->plus($fees[$i]),
                $balance,
            );
        }

        if (! $balance->isZero()) {
            throw new LogicException("Schedule does not amortize to zero (remaining: {$balance}).");
        }

        return new Schedule($items);
    }

    /** @return array{0: list<BigDecimal>, 1: list<BigDecimal>} */
    private function zeroSplit(BigDecimal $principal, int $n): array
    {
        return [MoneyMath::splitEvenly($principal, $n), array_fill(0, $n, BigDecimal::of('0.00'))];
    }

    /**
     * Flat: total interest = P x annual rate x (n / periods per year),
     * split evenly with the principal.
     *
     * @return array{0: list<BigDecimal>, 1: list<BigDecimal>}
     */
    private function flatSplit(InstallmentTerms $terms, int $perYear): array
    {
        $n = $terms->termCount;
        $totalInterest = MoneyMath::round2(MoneyMath::div(
            $terms->financedAmount
                ->multipliedBy(MoneyMath::percentToFraction($terms->annualRatePercent))
                ->multipliedBy($n),
            $perYear
        ));

        return [
            MoneyMath::splitEvenly($terms->financedAmount, $n),
            MoneyMath::splitEvenly($totalInterest, $n),
        ];
    }

    /**
     * Level payment (PMT = P*r*(1+r)^n / ((1+r)^n - 1)), interest on the remaining balance.
     * Used for both "reducing" (r = nominal / m) and "effective" (r from RateSolver).
     *
     * @return array{0: list<BigDecimal>, 1: list<BigDecimal>}
     */
    private function reducingSplit(BigDecimal $principal, int $n, BigDecimal $ratePerPeriod): array
    {
        if ($ratePerPeriod->isZero()) {
            return $this->zeroSplit($principal, $n); // PMT formula divides by zero at 0%
        }

        $factor = MoneyMath::pow(BigDecimal::one()->plus($ratePerPeriod), $n);
        $payment = MoneyMath::round2(MoneyMath::div(
            $principal->multipliedBy($ratePerPeriod)->multipliedBy($factor),
            $factor->minus(1)
        ));

        $principals = [];
        $interests = [];
        $balance = $principal;

        for ($i = 0; $i < $n - 1; $i++) {
            $interest = MoneyMath::round2($balance->multipliedBy($ratePerPeriod));
            $principalPart = $payment->minus($interest);

            if (! $principalPart->isPositive() || $principalPart->isGreaterThan($balance)) {
                throw new LogicException('Payment cannot amortize the balance with the given terms.');
            }

            $principals[] = $principalPart;
            $interests[] = $interest;
            $balance = $balance->minus($principalPart);
        }

        // Last installment: clear the remaining balance exactly.
        $principals[] = $balance;
        $interests[] = MoneyMath::round2($balance->multipliedBy($ratePerPeriod));

        return [$principals, $interests];
    }
}