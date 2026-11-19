<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class InstallmentApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/installments', [])->assertUnauthorized();
        $this->postJson('/api/installments/simulate', [])->assertUnauthorized();
    }

    public function test_creating_a_flat_plan_stores_the_plan_and_every_schedule_row(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments', $this->planPayload($account))
            ->assertCreated()
            ->assertJsonPath('data.financed_amount', '30000.00')
            ->assertJsonPath('data.total_interest', '3600.00')
            ->assertJsonPath('data.total_payable', '33600.00')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(12, 'data.schedule')
            ->assertJsonPath('data.schedule.0.total_due', '2800.00')
            ->assertJsonPath('data.schedule.0.due_date', '2026-12-10')
            ->assertJsonPath('data.schedule.11.remaining_principal', '0.00');

        $this->assertDatabaseCount('installment_plans', 1);
        $this->assertDatabaseCount('installment_schedule_items', 12);
    }

    public function test_a_stored_plan_can_be_read_back_but_not_by_another_user(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $account = $this->makeAccount($owner);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/installments', $this->planPayload($account))->json('data.id');

        $this->getJson("/api/installments/{$id}")->assertOk()->assertJsonCount(12, 'data.schedule');

        Sanctum::actingAs($this->makeUser('other@example.com'));
        $this->getJson("/api/installments/{$id}")->assertNotFound();
    }

    public function test_simulation_shows_the_schedule_the_true_rate_and_the_impact_without_saving(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/installments/simulate',
            $this->planPayload($account, ['from' => '2026-11-19', 'months' => 3])
        )
            ->assertOk()
            ->assertJsonPath('data.totals.monthly_payment', '2800.00')
            ->assertJsonPath('data.totals.interest', '3600.00')
            ->assertJsonPath('data.totals.payable', '33600.00')
            ->assertJsonCount(12, 'data.schedule')
            ->assertJsonPath('data.impact.extra_debt_service', '5600.00')   // 10 Dec and 10 Jan
            ->assertJsonPath('data.impact.final_closing_delta', '-5600.00');

        $rate = (float) $response->json('data.totals.effective_annual_rate_percent');
        $this->assertGreaterThan(20.0, $rate); // a "flat 12%" really costs more than 20% a year
        $this->assertLessThan(26.0, $rate);

        $this->assertDatabaseCount('installment_plans', 0);
    }

    public function test_a_zero_percent_plan_has_a_zero_effective_rate(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments/simulate', $this->planPayload($account, [
            'interest_method' => 'zero', 'annual_rate_percent' => '0', 'price' => '24000', 'term_count' => 10,
            'from' => '2026-11-19', 'months' => 3,
        ]))
            ->assertOk()
            ->assertJsonPath('data.totals.monthly_payment', '2400.00')
            ->assertJsonPath('data.totals.effective_annual_rate_percent', '0.0000');
    }

    public function test_a_plan_paid_by_card_lands_on_the_card_statements(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments/simulate', $this->planPayload($account, [
            'interest_method' => 'zero', 'annual_rate_percent' => '0', 'price' => '3000', 'term_count' => 3,
            'first_due_date' => '2026-11-20', 'funding_type' => 'credit_card', 'credit_card_id' => $card->id,
            'from' => '2026-11-19', 'months' => 3,
        ]))
            ->assertOk()
            ->assertJsonPath('data.impact.months.0.closing_delta', '0.00')
            ->assertJsonPath('data.impact.months.1.closing_delta', '-1000.00')   // 20 Nov charge => paid 25 Dec
            ->assertJsonPath('data.impact.months.2.closing_delta', '-2000.00')   // 20 Dec charge => paid 25 Jan
            ->assertJsonPath('data.impact.final_closing_delta', '-2000.00');
    }

    public function test_domain_rule_violations_are_validation_errors(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments', $this->planPayload($account, ['interest_method' => 'zero', 'annual_rate_percent' => '5']))
            ->assertUnprocessable()->assertJsonValidationErrors('terms');

        $this->postJson('/api/installments', $this->planPayload($account, ['fee_amount' => '100', 'fee_mode' => 'none']))
            ->assertUnprocessable()->assertJsonValidationErrors('terms');

        $this->postJson('/api/installments', $this->planPayload($account, ['down_payment' => '30000']))
            ->assertUnprocessable()->assertJsonValidationErrors('terms');

        $this->assertDatabaseCount('installment_plans', 0);
    }

    public function test_funding_must_exist_and_belong_to_the_user(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $strangerAccount = $this->makeAccount($this->makeUser('stranger@example.com'));
        Sanctum::actingAs($user);

        $this->postJson('/api/installments', $this->planPayload($account, ['account_id' => $strangerAccount->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->postJson('/api/installments', $this->planPayload($account, [
            'funding_type' => 'credit_card', 'credit_card_id' => (string) Str::uuid(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('credit_card_id');

        $payload = $this->planPayload($account);
        unset($payload['account_id']);
        $this->postJson('/api/installments', $payload)->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }
}