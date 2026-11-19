<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class WhatIfApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    /** @return list<string> */
    private function deltas(TestResponse $response): array
    {
        return array_column($response->json('data.comparison.months'), 'closing_delta');
    }

    private function runScenario(array $changes, int $months = 3): TestResponse
    {
        return $this->postJson('/api/what-if/run', ['from' => '2026-11-19', 'months' => $months, 'changes' => $changes]);
    }

    public function test_it_requires_authentication_and_changes(): void
    {
        $this->postJson('/api/what-if/run', [])->assertUnauthorized();

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/what-if/run', [])->assertUnprocessable()->assertJsonValidationErrors('changes');
    }

    public function test_cutting_an_expense_improves_later_months(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $mortgage = $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $response = $this->runScenario([[
            'type' => 'remove_source', 'source_type' => 'recurring_expense',
            'source_id' => $mortgage->id, 'from_date' => '2026-12-01',
        ]])->assertOk();

        $this->assertSame(['0.00', '7700.00', '15400.00'], $this->deltas($response));
        $response->assertJsonPath('data.comparison.final_closing_delta', '15400.00')
            ->assertJsonPath('data.before.final_closing', '82900.00')
            ->assertJsonPath('data.after.final_closing', '98300.00');
    }

    public function test_a_new_salary_from_december(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $salary = $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $response = $this->runScenario([[
            'type' => 'set_amount', 'source_type' => 'income_source',
            'source_id' => $salary->id, 'amount' => '35000', 'from_date' => '2026-12-01',
        ]])->assertOk();

        $this->assertSame(['0.00', '3000.00', '6000.00'], $this->deltas($response));
    }

    public function test_a_big_one_off_purchase_makes_a_month_risky(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        $this->setEmergencyFund($user, '3000.00');
        Sanctum::actingAs($user);

        $this->runScenario([[
            'type' => 'add_one_off', 'kind' => 'expense', 'name' => 'ซื้อของใหญ่',
            'amount' => '30000', 'date' => '2026-12-12',
        ]])
            ->assertOk()
            ->assertJsonPath('data.comparison.lowest_balance_before', '2300.00')
            ->assertJsonPath('data.comparison.lowest_balance_after', '-3400.00')
            ->assertJsonPath('data.comparison.new_risk_months', ['2026-12']);
    }

    public function test_extra_monthly_saving_changes_the_reserve_not_the_cash(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->runScenario([['type' => 'add_monthly_saving', 'name' => 'กองทุนฉุกเฉิน', 'monthly_amount' => '1000']])
            ->assertOk()
            ->assertJsonPath('data.comparison.final_closing_delta', '0.00')
            ->assertJsonPath('data.comparison.savings_reserved_delta', '3000.00');
    }

    public function test_adding_a_new_installment_reduces_future_cash(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->runScenario([array_merge(['type' => 'add_installment'], $this->planPayload($account))])
            ->assertOk()
            ->assertJsonPath('data.comparison.extra_debt_service', '5600.00')
            ->assertJsonPath('data.comparison.extra_interest', '600.00')
            ->assertJsonPath('data.comparison.final_closing_delta', '-5600.00');
    }

    public function test_paying_a_plan_off_early(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);
        $planId = $this->postJson('/api/installments', $this->planPayload($account))->json('data.id');

        // Rows 3..12 (due 10 Feb 2027 onward) = 25,000 principal + 3,000 interest = 28,000, paid off on 1 Feb.
        $response = $this->runScenario([['type' => 'payoff_plan', 'plan_id' => $planId, 'date' => '2027-02-01']], 4)->assertOk();

        $this->assertSame(['0.00', '0.00', '0.00', '-25200.00'], $this->deltas($response));
        $response->assertJsonPath('data.comparison.extra_debt_service', '25200.00')
            ->assertJsonPath('data.comparison.extra_interest', '2700.00');
    }

    public function test_a_payoff_before_the_forecast_start_is_rejected(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);
        $planId = $this->postJson('/api/installments', $this->planPayload($account))->json('data.id');

        $this->runScenario([['type' => 'payoff_plan', 'plan_id' => $planId, 'date' => '2026-11-01']])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.0.date');
    }

    public function test_changing_a_card_paid_item_is_not_supported_yet(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        $mortgage = $this->makeMortgage($user, $account, $card);
        Sanctum::actingAs($user);

        $this->runScenario([['type' => 'remove_source', 'source_type' => 'recurring_expense', 'source_id' => $mortgage->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.0.source_id');
    }

    public function test_each_change_is_validated_and_names_its_position(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->runScenario([['type' => 'add_one_off', 'kind' => 'expense', 'name' => 'x', 'amount' => '0', 'date' => '2026-12-12']])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.0.amount');

        $this->runScenario([['type' => 'teleport']])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.0.type');
    }
}