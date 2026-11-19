<?php

declare(strict_types=1);

namespace App\Domain\Finance\CreditCards;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure statement arithmetic. Interest and fees are NEVER computed here:
 * the user enters them from the bank's bill (agreed in Phase 1).
 *
 *   amount = purchases + installments + interest + fees + carriedOver - refunds
 */
final class StatementCalculator
{
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';

    public const PAYMENT_FULL = 'full';
    public const PAYMENT_MINIMUM = 'minimum';
    public const PAYMENT_PARTIAL = 'partial';

    public function summarize(
        BigDecimal|string $purchases = '0',
        BigDecimal|string $installments = '0',
        BigDecimal|string $interest = '0',
        BigDecimal|string $fees = '0',
        BigDecimal|string $refunds = '0',
        BigDecimal|string $carriedOver = '0',
        BigDecimal|string $paid = '0',
    ): StatementSummary {
        $zero = BigDecimal::of('0.00');

        $raw = self::money('purchases', $purchases)
            ->plus(self::money('installments', $installments))
            ->plus(self::money('interest', $interest))
            ->plus(self::money('fees', $fees))
            ->plus(self::money('carried over', $carriedOver))
            ->minus(self::money('refunds', $refunds));

        $amount = $raw->isNegative() ? $zero : $raw;
        $credit = $raw->isNegative() ? $raw->negated() : $zero;

        $paidAmount = self::money('paid', $paid);
        $remaining = $amount->minus($paidAmount);
        $outstanding = $remaining->isPositive() ? $remaining : $zero;

        // Nothing left to pay => paid (this includes a statement of 0.00).
        $status = match (true) {
            $outstanding->isZero() => self::STATUS_PAID,
            $paidAmount->isZero() => self::STATUS_UNPAID,
            default => self::STATUS_PARTIAL,
        };

        return new StatementSummary($amount, $credit, $paidAmount, $outstanding, $status);
    }

    /** Overdue = something is still owed and the due date has passed (due day itself is not overdue). */
    public function isOverdue(StatementSummary $summary, DateTimeImmutable $dueDate, DateTimeImmutable $today): bool
    {
        return $summary->outstanding->isPositive()
            && $today->format('Y-m-d') > $dueDate->format('Y-m-d');
    }

    /**
     * Validates a payment and labels it full / minimum / partial.
     * Overpayment is rejected: the database cannot carry a credit forward.
     * Paying less than the minimum is allowed (it is the user's real situation) and is "partial".
     */
    public function classifyPayment(
        BigDecimal|string $amount,
        BigDecimal|string $outstanding,
        BigDecimal|string $minPayment = '0',
    ): string {
        $amount = MoneyMath::exact2($amount);
        $outstanding = self::money('outstanding', $outstanding);
        $minPayment = self::money('minimum payment', $minPayment);

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A payment must be greater than 0.');
        }
        if ($amount->isGreaterThan($outstanding)) {
            throw new InvalidArgumentException("Payment {$amount} is more than the outstanding {$outstanding}.");
        }

        if ($amount->isEqualTo($outstanding)) {
            return self::PAYMENT_FULL;
        }
        if ($minPayment->isPositive() && $amount->isEqualTo($minPayment)) {
            return self::PAYMENT_MINIMUM;
        }

        return self::PAYMENT_PARTIAL;
    }

    /**
     * Amount carried into the NEXT statement: what was still unpaid on the next
     * statement date. Only payments dated on or before that day count; later ones
     * belong to the newer statement (see Phase 4.3 design note 3).
     *
     * @param list<CardPayment> $payments payments recorded against the previous statement
     */
    public function carriedOver(
        BigDecimal|string $previousStatementAmount,
        array $payments,
        DateTimeImmutable $nextStatementDate,
    ): BigDecimal {
        $previous = self::money('previous statement amount', $previousStatementAmount);
        $cutoff = $nextStatementDate->format('Y-m-d');

        $paid = BigDecimal::of('0.00');
        foreach ($payments as $payment) {
            if ($payment->date->format('Y-m-d') <= $cutoff) {
                $paid = $paid->plus($payment->amount);
            }
        }

        $left = $previous->minus($paid);

        return $left->isPositive() ? $left : BigDecimal::of('0.00');
    }

    private static function money(string $label, BigDecimal|string $value): BigDecimal
    {
        $decimal = MoneyMath::exact2($value);

        if ($decimal->isNegative()) {
            throw new InvalidArgumentException("The {$label} amount must not be negative.");
        }

        return $decimal;
    }
}