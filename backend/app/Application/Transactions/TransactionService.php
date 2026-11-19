<?php

declare(strict_types=1);

namespace App\Application\Transactions;

use App\Application\Statements\StatementRecalculator;
use App\Application\Statements\StatementService;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Support\Day;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Every write to transactions goes through here so card statements always stay in step. */
final class TransactionService
{
    private const CARD_TYPES = ['card_purchase', 'card_refund'];

    public function __construct(
        private readonly StatementService $statements,
        private readonly StatementRecalculator $recalculator,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data): Transaction {
            $transaction = new Transaction();
            $transaction->fill($this->normalize($data));
            $this->attachStatement($transaction);
            $transaction->save();
            $this->recalculate([$transaction->credit_card_id]);

            return $transaction->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data): Transaction {
            // Rows created by a settlement or an installment purchase keep their links:
            // only descriptive fields, amount and date may change.
            $linked = $transaction->recurring_expense_id !== null
                || $transaction->income_source_id !== null
                || $transaction->installment_plan_id !== null;
            $oldCard = $transaction->credit_card_id;

            $transaction->fill($linked
                ? Arr::only($data, ['name', 'category_id', 'amount', 'txn_date', 'note'])
                : $this->normalize($data));
            $this->attachStatement($transaction);
            $transaction->save();
            $this->recalculate([$oldCard, $transaction->credit_card_id]);

            return $transaction->refresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $cardId = $transaction->credit_card_id;
            $transaction->delete();
            $this->recalculate([$cardId]);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        if (in_array($data['type'] ?? null, self::CARD_TYPES, true)) {
            $data['payment_method'] = 'credit_card';
            $data['account_id'] = null;
        } else {
            $data['credit_card_id'] = null;
        }

        return $data;
    }

    private function attachStatement(Transaction $transaction): void
    {
        if (! in_array($transaction->type, self::CARD_TYPES, true)) {
            $transaction->statement_id = null;

            return;
        }
        if ($transaction->installment_plan_id !== null) {
            return; // the full-price record of an installment purchase is never billed as such
        }

        $card = CreditCard::query()->findOrFail($transaction->credit_card_id);
        $transaction->statement_id = $this->statements->statementFor($card, Day::of($transaction->txn_date))->id;
    }

    /** @param list<?string> $cardIds */
    private function recalculate(array $cardIds): void
    {
        foreach (array_unique(array_filter($cardIds)) as $cardId) {
            $this->recalculator->recalculateCard(CreditCard::query()->findOrFail($cardId));
        }
    }
}