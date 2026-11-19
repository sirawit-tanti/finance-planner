<?php

namespace Tests\Feature\Concerns;

use App\Application\Auth\RegisterUser;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Models\IncomeSource;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Models\UserSetting;
use DateTimeImmutable;

/** Builders for test data. user_id is always set explicitly (nobody is logged in while seeding). */
trait FinanceFixtures
{
    protected function makeUser(string $email = 'tester@example.com'): User
    {
        return (new RegisterUser())->handle(['name' => 'Tester', 'email' => $email, 'password' => 'password123']);
    }

    protected function makeAccount(User $user, string $opening = '10000.00', string $openingDate = '2026-11-01'): Account
    {
        $account = new Account();
        $account->forceFill([
            'user_id' => $user->id, 'name' => 'บัญชีหลัก', 'type' => 'savings',
            'opening_balance' => $opening, 'opening_date' => $openingDate,
        ])->save();

        return $account;
    }

    protected function makeSalary(User $user, Account $account, string $amount = '32000.00', int $day = 25): IncomeSource
    {
        $source = new IncomeSource();
        $source->forceFill([
            'user_id' => $user->id, 'name' => 'เงินเดือน', 'amount' => $amount, 'frequency' => 'monthly',
            'pay_day' => $day, 'start_date' => '2026-01-01', 'shift_rule' => 'none',
            'account_id' => $account->id, 'is_active' => true,
        ])->save();

        return $source;
    }

    protected function makeMortgage(User $user, ?Account $account, ?CreditCard $card = null): RecurringExpense
    {
        $expense = new RecurringExpense();
        $expense->forceFill([
            'user_id' => $user->id,
            'name' => 'ค่าผ่อนบ้าน',
            'category_id' => Category::withoutGlobalScopes()->where('user_id', $user->id)->value('id'),
            'amount' => '7700.00', 'frequency' => 'monthly', 'due_day' => $card === null ? 20 : 10,
            'start_date' => '2026-01-01', 'shift_rule' => 'none',
            'payment_method' => $card === null ? 'transfer' : 'credit_card',
            'account_id' => $card === null ? $account->id : null,
            'credit_card_id' => $card?->id,
        ])->save();

        return $expense;
    }

    protected function makeCard(User $user, Account $account): CreditCard
    {
        $card = new CreditCard();
        $card->forceFill([
            'user_id' => $user->id, 'name' => 'บัตร A', 'bank' => 'KBank', 'credit_limit' => '50000.00',
            'statement_day' => 5, 'due_day' => 25, 'pay_account_id' => $account->id,
        ])->save();

        return $card;
    }

    protected function makeStatement(
        User $user,
        CreditCard $card,
        string $statementDate,
        string $dueDate,
        string $amount,
        string $paid = '0.00',
    ): CreditCardStatement {
        $statement = new CreditCardStatement();
        $statement->forceFill([
            'user_id' => $user->id, 'credit_card_id' => $card->id,
            'cycle_start' => (new DateTimeImmutable($statementDate))->modify('-27 days')->format('Y-m-d'),
            'statement_date' => $statementDate, 'due_date' => $dueDate,
            'statement_amount' => $amount, 'paid_amount' => $paid,
            'status' => $paid === '0.00' ? 'unpaid' : 'partial',
        ])->save();

        return $statement;
    }

    /** Written with a plain UPDATE (no model events), so call it BEFORE the first forecast request. */
    protected function setEmergencyFund(User $user, string $amount): void
    {
        UserSetting::withoutGlobalScopes()->where('user_id', $user->id)->update(['emergency_fund_amount' => $amount]);
    }

    /** Flat 30,000 @ 12% for 12 months, first due 10 Dec 2026, paid from the account. */
    protected function planPayload(Account $account, array $override = []): array
    {
        return array_merge([
            'name' => 'ผ่อนโทรศัพท์', 'interest_method' => 'flat', 'annual_rate_percent' => '12',
            'price' => '30000', 'down_payment' => '0', 'term_count' => 12, 'frequency' => 'monthly',
            'start_date' => '2026-11-01', 'first_due_date' => '2026-12-10',
            'fee_amount' => '0', 'fee_mode' => 'none',
            'funding_type' => 'account', 'account_id' => $account->id,
        ], $override);
    }
}