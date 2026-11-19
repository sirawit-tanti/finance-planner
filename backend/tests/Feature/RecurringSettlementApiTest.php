<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class RecurringSettlementApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    public function test_settling_an_occurrence_records_it_once_and_stops_the_forecast_expecting_it(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $mortgage = $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $this->postJson("/api/recurring-expenses/{$mortgage->id}/settle", ['occurrence_date' => '2026-11-20'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.amount', '7700.00')
            ->assertJsonPath('data.txn_date', '2026-11-19');

        $this->postJson("/api/recurring-expenses/{$mortgage->id}/settle", ['occurrence_date' => '2026-11-20'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurrence_date');

        $this->getJson('/api/forecast?from=2026-11-19&months=2')
            ->assertOk()
            ->assertJsonPath('data.opening_cash', '2300.00')
            ->assertJsonPath('data.months.0.expense_recurring', '0.00')
            ->assertJsonPath('data.months.0.closing_cash', '34300.00')
            ->assertJsonPath('data.months.1.expense_recurring', '7700.00');
    }

    public function test_receiving_an_income_occurrence(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $salary = $this->makeSalary($user, $account);
        Sanctum::actingAs($user);

        $this->postJson("/api/income-sources/{$salary->id}/receive", ['occurrence_date' => '2026-11-25'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.amount', '32000.00');

        $this->getJson('/api/forecast?from=2026-11-19&months=1')
            ->assertJsonPath('data.opening_cash', '42000.00')
            ->assertJsonPath('data.months.0.income_recurring', '0.00');
    }

    public function test_an_override_sets_the_real_amount_of_one_occurrence_and_is_not_duplicated(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $mortgage = $this->makeMortgage($user, $account);
        Sanctum::actingAs($user);

        $body = [
            'source_type' => 'expense', 'source_id' => $mortgage->id,
            'occurrence_date' => '2026-12-20', 'override_amount' => '8000',
        ];
        $this->postJson('/api/recurring-overrides', $body)->assertCreated();
        $this->postJson('/api/recurring-overrides', ['override_amount' => '8500'] + $body)
            ->assertOk()->assertJsonPath('data.override_amount', '8500.00');
        $this->getJson('/api/recurring-overrides')->assertJsonCount(1, 'data');

        $this->getJson('/api/forecast?from=2026-11-19&months=2')
            ->assertJsonPath('data.months.0.expense_recurring', '7700.00')
            ->assertJsonPath('data.months.1.expense_recurring', '8500.00');
    }

    public function test_a_card_paid_bill_becomes_a_card_purchase_in_the_right_statement(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        $mortgage = $this->makeMortgage($user, $account, $card);
        Sanctum::actingAs($user);

        $this->postJson("/api/recurring-expenses/{$mortgage->id}/settle", ['occurrence_date' => '2026-11-10'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'card_purchase')
            ->assertJsonPath('data.payment_method', 'credit_card');

        $this->getJson("/api/credit-cards/{$card->id}/statements")
            ->assertJsonPath('data.0.statement_date', '2026-12-05')
            ->assertJsonPath('data.0.purchases_total', '7700.00');

        // No cash has left the account yet.
        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '10000.00');
    }

    public function test_an_override_for_a_foreign_rule_is_rejected(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $mortgage = $this->makeMortgage($owner, $this->makeAccount($owner));

        Sanctum::actingAs($this->makeUser('other@example.com'));
        $this->postJson('/api/recurring-overrides', [
            'source_type' => 'expense', 'source_id' => $mortgage->id, 'occurrence_date' => '2026-12-20', 'override_amount' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('source_id');
    }
}