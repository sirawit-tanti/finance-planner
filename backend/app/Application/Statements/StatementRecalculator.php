<?php

declare(strict_types=1);

namespace App\Application\Statements;

use App\Domain\Finance\CreditCards\CardPayment;
use App\Domain\Finance\CreditCards\StatementCalculator;
use App\Domain\Finance\Money\MoneyMath;
use App\Models\CreditCard;
use App\Models\CreditCardPayment;
use App\Models\CreditCardStatement;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Transaction;
use App\Support\Day;
use App\Support\Sql;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Rebuilds every stored figure of a card's statements, oldest first, inside the caller's transaction:
 *   amount = purchases + installments + interest + fees + carriedOver - refunds
 *   carriedOver(first) = card opening balance
 *   carriedOver(next)  = what was still unpaid on the previous statement at THIS statement's date
 * Interest, fees and minimum payment are user input and are never touched here.
 */
final class StatementRecalculator
{
    private readonly StatementCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new StatementCalculator();
    }

    public function recalculateCard(CreditCard $card): void
    {
        $statements = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)->orderBy('statement_date')->get();

        $previous = null;
        foreach ($statements as $statement) {
            $this->linkInstallmentRows($card, $statement);

            $purchases = Sql::sum(
                Transaction::query()->where('statement_id', $statement->id)
                    ->where('type', 'card_purchase')->whereNull('installment_plan_id')
            );
            $refunds = Sql::sum(
                Transaction::query()->where('statement_id', $statement->id)->where('type', 'card_refund')
            );
            $installments = Sql::sum(
                InstallmentScheduleItem::query()->where('statement_id', $statement->id)->where('status', '!=', 'cancelled'),
                'total_due'
            );
            $paid = Sql::sum(CreditCardPayment::query()->where('statement_id', $statement->id));

            $carried = $previous === null
                ? MoneyMath::exact2((string) $card->opening_balance)
                : $this->carriedFrom($previous, Day::of($statement->statement_date));

            $summary = $this->calculator->summarize(
                $purchases, $installments, (string) $statement->interest_charged,
                (string) $statement->fees_charged, $refunds, $carried, $paid,
            );

            $statement->fill([
                'purchases_total' => (string) $purchases,
                'installments_total' => (string) $installments,
                'refunds_total' => (string) $refunds,
                'carried_over' => (string) $carried,
                'statement_amount' => (string) $summary->statementAmount,
                'paid_amount' => (string) $summary->paidAmount,
                'status' => $summary->status,
            ]);
            if ($statement->isDirty()) {
                $statement->save();
            }

            $previous = $statement;
        }
    }

    private function carriedFrom(CreditCardStatement $previous, DateTimeImmutable $nextStatementDate): BigDecimal
    {
        $payments = CreditCardPayment::query()->where('statement_id', $previous->id)->get()
            ->map(fn ($p) => new CardPayment(Day::of($p->payment_date), (string) $p->amount))
            ->all();

        return $this->calculator->carriedOver((string) $previous->statement_amount, $payments, $nextStatementDate);
    }

    /** Installment rows of card-funded plans that are charged inside this cycle become part of this statement. */
    private function linkInstallmentRows(CreditCard $card, CreditCardStatement $statement): void
    {
        $planIds = InstallmentPlan::query()
            ->where('funding_type', 'credit_card')->where('credit_card_id', $card->id)
            ->where('status', '!=', 'cancelled')->pluck('id');

        if ($planIds->isEmpty()) {
            return;
        }

        InstallmentScheduleItem::query()
            ->whereIn('plan_id', $planIds)->whereNull('statement_id')->where('status', '!=', 'cancelled')
            ->whereBetween('due_date', [$statement->cycle_start->format('Y-m-d'), $statement->statement_date->format('Y-m-d')])
            ->get()
            ->each(function (InstallmentScheduleItem $row) use ($statement): void {
                $row->statement_id = $statement->id;
                $row->save();
            });
    }
}