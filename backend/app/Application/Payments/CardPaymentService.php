<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Statements\StatementRecalculator;
use App\Domain\Finance\CreditCards\StatementAssigner;
use App\Domain\Finance\CreditCards\StatementCalculator;
use App\Domain\Finance\Money\MoneyMath;
use App\Models\CreditCard;
use App\Models\CreditCardPayment;
use App\Models\CreditCardStatement;
use App\Support\Day;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CardPaymentService
{
    private readonly StatementCalculator $calculator;
    private readonly StatementAssigner $assigner;

    public function __construct(private readonly StatementRecalculator $recalculator)
    {
        $this->calculator = new StatementCalculator();
        $this->assigner = new StatementAssigner();
    }

    /** @param array{account_id: string, payment_date: string, amount: string|int|float, note?: ?string} $data */
    public function pay(CreditCardStatement $statement, array $data): CreditCardPayment
    {
        return DB::transaction(function () use ($statement, $data): CreditCardPayment {
            $locked = CreditCardStatement::query()->lockForUpdate()->findOrFail($statement->id);
            $card = CreditCard::query()->findOrFail($locked->credit_card_id);
            $date = Day::parse($data['payment_date']);

            // A payment dated after the next statement date would be counted twice
            // (once here, once in the next statement's carried-over amount).
            $limit = $this->nextStatementDate($card, $locked);
            if ($date->format('Y-m-d') > $limit->format('Y-m-d')) {
                throw ValidationException::withMessages(['payment_date' => [
                    'บิลนี้ถูกยกยอดไปรอบถัดไปแล้ว ให้ชำระที่รอบล่าสุด (ลงวันที่ได้ไม่เกิน ' . $limit->format('Y-m-d') . ')',
                ]]);
            }

            $outstanding = MoneyMath::exact2((string) $locked->statement_amount)
                ->minus(MoneyMath::exact2((string) $locked->paid_amount));
            if ($outstanding->isNegative()) {
                $outstanding = $outstanding->multipliedBy(0)->toScale(2);
            }

            try {
                $kind = $this->calculator->classifyPayment((string) $data['amount'], $outstanding, (string) $locked->min_payment);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['amount' => [
                    "จำนวนเงินต้องมากกว่า 0 และไม่เกินยอดค้างชำระ ({$outstanding})",
                ]]);
            }

            $payment = new CreditCardPayment();
            $payment->fill([
                'statement_id' => $locked->id,
                'account_id' => $data['account_id'],
                'payment_date' => $data['payment_date'],
                'amount' => (string) $data['amount'],
                'kind' => $kind,
                'note' => $data['note'] ?? null,
            ])->save();

            $this->recalculator->recalculateCard($card);

            return $payment;
        });
    }

    public function delete(CreditCardPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $statement = CreditCardStatement::query()->findOrFail($payment->statement_id);
            $card = CreditCard::query()->findOrFail($statement->credit_card_id);

            $payment->delete();
            $this->recalculator->recalculateCard($card);
        });
    }

    private function nextStatementDate(CreditCard $card, CreditCardStatement $statement): DateTimeImmutable
    {
        $date = Day::of($statement->statement_date);
        $index = ((int) $date->format('Y')) * 12 + ((int) $date->format('n') - 1) + 1;

        return $this->assigner->cycleForStatementMonth(
            (int) $card->statement_day, (int) $card->due_day, intdiv($index, 12), $index % 12 + 1
        )->statementDate;
    }
}