<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\CreditCards\CardPayment;
use App\Domain\Finance\CreditCards\StatementCalculator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StatementCalculatorTest extends TestCase
{
    private StatementCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new StatementCalculator();
    }

    public function test_plain_statement_is_unpaid(): void
    {
        $s = $this->calc->summarize(purchases: '5000', installments: '1200');

        $this->assertSame('6200.00', (string) $s->statementAmount);
        $this->assertSame('6200.00', (string) $s->outstanding);
        $this->assertSame('0.00', (string) $s->creditBalance);
        $this->assertSame('unpaid', $s->status);
    }

    public function test_all_components_follow_the_formula(): void
    {
        // 5000 + 1200 + 150.50 + 100 + 2000 (carried) - 300 (refunds) = 8150.50
        $s = $this->calc->summarize(
            purchases: '5000', installments: '1200', interest: '150.50',
            fees: '100', refunds: '300', carriedOver: '2000'
        );

        $this->assertSame('8150.50', (string) $s->statementAmount);
    }

    public function test_partial_payment(): void
    {
        $s = $this->calc->summarize(
            purchases: '5000', installments: '1200', interest: '150.50',
            fees: '100', refunds: '300', carriedOver: '2000', paid: '1000'
        );

        $this->assertSame('7150.50', (string) $s->outstanding);
        $this->assertSame('1000.00', (string) $s->paidAmount);
        $this->assertSame('partial', $s->status);
    }

    public function test_full_payment(): void
    {
        $s = $this->calc->summarize(purchases: '8150.50', paid: '8150.50');

        $this->assertSame('0.00', (string) $s->outstanding);
        $this->assertSame('paid', $s->status);
    }

    public function test_overpaid_statement_never_shows_a_negative_outstanding(): void
    {
        $s = $this->calc->summarize(purchases: '100', paid: '150');

        $this->assertSame('0.00', (string) $s->outstanding);
        $this->assertSame('paid', $s->status);
    }

    public function test_refunds_above_charges_become_a_credit_balance(): void
    {
        $s = $this->calc->summarize(purchases: '100', refunds: '250');

        $this->assertSame('0.00', (string) $s->statementAmount);
        $this->assertSame('150.00', (string) $s->creditBalance);
        $this->assertSame('0.00', (string) $s->outstanding);
        $this->assertSame('paid', $s->status);
    }

    public function test_an_empty_statement_is_paid(): void
    {
        $this->assertSame('paid', $this->calc->summarize()->status);
    }

    public function test_negative_or_three_decimal_inputs_are_rejected(): void
    {
        foreach ([['purchases' => '-1'], ['fees' => '10.001']] as $args) {
            try {
                $this->calc->summarize(...$args);
                $this->fail('Expected InvalidArgumentException for ' . json_encode($args));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- Overdue ----

    public function test_overdue_only_after_the_due_day_and_only_when_money_is_owed(): void
    {
        $due = new DateTimeImmutable('2026-11-25');
        $unpaid = $this->calc->summarize(purchases: '1000');
        $paid = $this->calc->summarize(purchases: '1000', paid: '1000');

        $this->assertFalse($this->calc->isOverdue($unpaid, $due, new DateTimeImmutable('2026-11-25')));
        $this->assertTrue($this->calc->isOverdue($unpaid, $due, new DateTimeImmutable('2026-11-26')));
        $this->assertFalse($this->calc->isOverdue($paid, $due, new DateTimeImmutable('2026-11-26')));
    }

    // ---- Payment classification ----

    public function test_payment_kinds(): void
    {
        $this->assertSame('full', $this->calc->classifyPayment('6200', '6200', '310'));
        $this->assertSame('minimum', $this->calc->classifyPayment('310', '6200', '310'));
        $this->assertSame('partial', $this->calc->classifyPayment('1000', '6200', '310'));
        $this->assertSame('partial', $this->calc->classifyPayment('100', '6200', '310')); // below the minimum is allowed
        $this->assertSame('partial', $this->calc->classifyPayment('310', '6200', '0'));   // no minimum recorded
    }

    public function test_paying_the_whole_balance_is_full_even_if_it_equals_the_minimum(): void
    {
        $this->assertSame('full', $this->calc->classifyPayment('310', '310', '310'));
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->classifyPayment('6200.01', '6200');
    }

    public function test_zero_payment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->classifyPayment('0', '6200');
    }

    // ---- Carry over ----

    public function test_only_payments_up_to_the_next_statement_date_reduce_the_carried_amount(): void
    {
        $payments = [
            new CardPayment(new DateTimeImmutable('2026-11-20'), '2000'),
            new CardPayment(new DateTimeImmutable('2026-12-10'), '1000'), // after the 5 Dec statement
        ];

        $carried = $this->calc->carriedOver('5000', $payments, new DateTimeImmutable('2026-12-05'));

        $this->assertSame('3000.00', (string) $carried);
    }

    public function test_a_payment_on_the_statement_date_counts(): void
    {
        $payments = [new CardPayment(new DateTimeImmutable('2026-12-05'), '500')];

        $this->assertSame(
            '4500.00',
            (string) $this->calc->carriedOver('5000', $payments, new DateTimeImmutable('2026-12-05'))
        );
    }

    public function test_nothing_is_carried_when_the_statement_was_paid(): void
    {
        $payments = [new CardPayment(new DateTimeImmutable('2026-11-25'), '5000')];

        $this->assertSame(
            '0.00',
            (string) $this->calc->carriedOver('5000', $payments, new DateTimeImmutable('2026-12-05'))
        );
    }

    public function test_everything_is_carried_when_nothing_was_paid(): void
    {
        $this->assertSame(
            '5000.00',
            (string) $this->calc->carriedOver('5000', [], new DateTimeImmutable('2026-12-05'))
        );
    }
}