<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Transactions\TransactionService;
use App\Models\IncomeSource;
use App\Models\RecurringExpense;
use App\Models\RecurringOverride;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Marks ONE occurrence of a recurring bill / income as paid / received by recording the real transaction.
 * The transaction carries (rule id, original occurrence date), so the forecast stops expecting it,
 * and a unique index stops the same occurrence being recorded twice (double tap, offline retry).
 */
final class RecurringSettlementService
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    public function settleExpense(RecurringExpense $rule, string $occurrence, string $txnDate, ?string $amount, ?string $note): Transaction
    {
        $exists = Transaction::query()->where('recurring_expense_id', $rule->id)->whereDate('occurrence_date', $occurrence)->exists();
        $amount ??= $this->overrideAmount('expense', (string) $rule->id, $occurrence) ?? (string) $rule->amount;

        $isCard = $rule->payment_method === 'credit_card';

        return $this->record($exists, [
            'type' => $isCard ? 'card_purchase' : 'expense',
            'txn_date' => $txnDate,
            'name' => $rule->name,
            'category_id' => $rule->category_id,
            'amount' => $amount,
            'note' => $note,
            'payment_method' => $isCard ? 'credit_card' : ($rule->payment_method === 'cash' ? 'cash' : 'transfer'),
            'account_id' => $isCard ? null : $rule->account_id,
            'credit_card_id' => $isCard ? $rule->credit_card_id : null,
            'recurring_expense_id' => $rule->id,
            'occurrence_date' => $occurrence,
        ]);
    }

    public function receiveIncome(IncomeSource $source, string $occurrence, string $txnDate, ?string $amount, ?string $note): Transaction
    {
        $exists = Transaction::query()->where('income_source_id', $source->id)->whereDate('occurrence_date', $occurrence)->exists();
        $amount ??= $this->overrideAmount('income', (string) $source->id, $occurrence) ?? (string) $source->amount;

        return $this->record($exists, [
            'type' => 'income',
            'txn_date' => $txnDate,
            'name' => $source->name,
            'amount' => $amount,
            'note' => $note,
            'payment_method' => 'transfer',
            'account_id' => $source->account_id,
            'income_source_id' => $source->id,
            'occurrence_date' => $occurrence,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function record(bool $alreadyDone, array $data): Transaction
    {
        if ($alreadyDone) {
            throw ValidationException::withMessages(['occurrence_date' => ['รอบนี้บันทึกไปแล้ว']]);
        }
        if (! is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => ['จำนวนเงินต้องมากกว่า 0']]);
        }

        try {
            return $this->transactions->create($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['occurrence_date' => ['รอบนี้บันทึกไปแล้ว']]);
        }
    }

    private function overrideAmount(string $type, string $id, string $occurrence): ?string
    {
        $override = RecurringOverride::query()
            ->where('source_type', $type)->where('source_id', $id)->whereDate('occurrence_date', $occurrence)->first();

        return $override?->override_amount === null ? null : (string) $override->override_amount;
    }
}