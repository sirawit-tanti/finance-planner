<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class ForecastApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    private const URL = '/api/forecast?from=2026-11-19&months=3';

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/forecast')->assertUnauthorized();
    }

    public function test_salary_and_mortgage_produce_the_expected_monthly_balances(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('data.opening_cash', '10000.00')
            ->assertJsonPath('data.months.0.key', '2026-11')
            ->assertJsonPath('data.months.0.is_partial', true)
            ->assertJsonPath('data.months.0.income_recurring', '32000.00')
            ->assertJsonPath('data.months.0.expense_recurring', '7700.00')
            ->assertJsonPath('data.months.0.min_balance', '2300.00')
            ->assertJsonPath('data.months.0.closing_cash', '34300.00')
            ->assertJsonPath('data.months.1.closing_cash', '58600.00')
            ->assertJsonPath('data.months.2.closing_cash', '82900.00')
            ->assertJsonPath('data.summary.final_closing', '82900.00')
            ->assertJsonPath('data.summary.closing_after.1', '34300.00');
    }

    public function test_an_occurrence_already_paid_is_not_deducted_twice(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $mortgage = $this->makeMortgage($user, $account);

        // The 20 Nov payment was made early, on 18 Nov.
        (new Transaction())->forceFill([
            'user_id' => $user->id, 'type' => 'expense', 'txn_date' => '2026-11-18', 'name' => 'ค่าผ่อนบ้าน',
            'amount' => '7700.00', 'payment_method' => 'transfer', 'account_id' => $account->id,
            'recurring_expense_id' => $mortgage->id, 'occurrence_date' => '2026-11-20',
        ])->save();
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.opening_cash', '2300.00')                 // the real payment is in the cash balance
            ->assertJsonPath('data.months.0.expense_recurring', '0.00')      // and is not forecast again
            ->assertJsonPath('data.months.0.closing_cash', '34300.00')
            ->assertJsonPath('data.months.1.expense_recurring', '7700.00');
    }

    public function test_only_the_latest_card_statement_is_paid_because_it_carries_older_balances(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        $card = $this->makeCard($user, $account);
        $this->makeStatement($user, $card, '2026-10-05', '2026-10-25', '3000.00');
        $this->makeStatement($user, $card, '2026-11-05', '2026-11-25', '5000.00');
        Sanctum::actingAs($user);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.months.0.card_payments', '5000.00')
            ->assertJsonPath('data.months.0.closing_cash', '29300.00');
    }

    public function test_a_new_installment_plan_flows_into_the_forecast_with_interest_separated(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments', $this->planPayload($account))->assertCreated();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.months.0.installments_total', '0.00')
            ->assertJsonPath('data.months.1.installments_total', '2800.00')
            ->assertJsonPath('data.months.1.installment_principal', '2500.00')
            ->assertJsonPath('data.months.1.installment_interest', '300.00')
            ->assertJsonPath('data.months.1.closing_cash', '55800.00')
            ->assertJsonPath('data.months.2.closing_cash', '77300.00')
            ->assertJsonPath('data.summary.total_installments', '5600.00')
            ->assertJsonPath('data.summary.total_interest', '600.00');
    }

    public function test_a_budget_is_used_for_everyday_spending(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user);
        (new Budget())->forceFill([
            'user_id' => $user->id,
            'category_id' => Category::withoutGlobalScopes()->where('user_id', $user->id)->value('id'),
            'amount' => '30000.00',
        ])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/forecast?from=2026-11-19&months=2')
            ->assertOk()
            ->assertJsonPath('data.months.0.expense_general', '30000.00')
            ->assertJsonPath('data.months.0.closing_cash', '-20000.00')
            ->assertJsonPath('data.months.0.risk', 'negative')
            ->assertJsonPath('data.months.1.expense_general', '30000.00');
    }

    public function test_the_stored_forecast_is_reused_until_the_data_changes(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('cached', false);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('cached', true);
        $this->getJson(self::URL . '&refresh=1')->assertOk()->assertJsonPath('cached', false);

        $account->opening_balance = '20000.00';
        $account->save(); // bumps data_version

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('data.opening_cash', '20000.00');
    }

    public function test_another_users_data_never_appears(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $this->makeAccount($alice, '1000.00');
        $bob = $this->makeUser('bob@example.com');
        $this->makeAccount($bob, '99999.00');
        Sanctum::actingAs($alice);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.opening_cash', '1000.00');
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/forecast?months=37')->assertUnprocessable()->assertJsonValidationErrors('months');
        $this->getJson('/api/forecast?from=2026-02-31')->assertUnprocessable()->assertJsonValidationErrors('from');
    }
}