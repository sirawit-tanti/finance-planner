<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class StatementServiceTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    private function purchase(string $cardId, string $date, string $amount, string $type = 'card_purchase'): void
    {
        $this->postJson('/api/transactions', [
            'type' => $type, 'txn_date' => $date, 'name' => 'ซื้อของ', 'amount' => $amount, 'credit_card_id' => $cardId,
        ])->assertCreated();
    }

    public function test_purchases_land_in_the_right_statement_and_the_balance_is_carried_forward(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard($user, $this->makeAccount($user));
        Sanctum::actingAs($user);

        $this->purchase($card->id, '2026-11-03', '1000');                 // statement of 5 Nov
        $this->purchase($card->id, '2026-11-06', '500');                  // statement of 5 Dec
        $this->purchase($card->id, '2026-11-04', '200', 'card_refund');   // refund inside the 5 Nov statement

        $this->getJson("/api/credit-cards/{$card->id}/statements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.statement_date', '2026-12-05')
            ->assertJsonPath('data.0.cycle_start', '2026-11-06')
            ->assertJsonPath('data.0.due_date', '2026-12-25')
            ->assertJsonPath('data.0.purchases_total', '500.00')
            ->assertJsonPath('data.0.carried_over', '800.00')
            ->assertJsonPath('data.0.statement_amount', '1300.00')
            ->assertJsonPath('data.0.outstanding', '1300.00')
            ->assertJsonPath('data.1.statement_date', '2026-11-05')
            ->assertJsonPath('data.1.cycle_start', '2026-10-06')
            ->assertJsonPath('data.1.due_date', '2026-11-25')
            ->assertJsonPath('data.1.purchases_total', '1000.00')
            ->assertJsonPath('data.1.refunds_total', '200.00')
            ->assertJsonPath('data.1.statement_amount', '800.00');
    }

    public function test_the_opening_balance_is_carried_into_the_first_statement(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard($user, $this->makeAccount($user));
        Sanctum::actingAs($user);

        $this->putJson("/api/credit-cards/{$card->id}", [
            'name' => 'บัตร A', 'bank' => 'KBank', 'statement_day' => 5, 'due_day' => 25, 'opening_balance' => '2500',
        ])->assertOk();

        $this->getJson("/api/credit-cards/{$card->id}/statements")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.carried_over', '2500.00')
            ->assertJsonPath('data.0.statement_amount', '2500.00');
    }

    public function test_interest_and_fees_entered_from_the_bill_change_the_amount(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard($user, $this->makeAccount($user));
        Sanctum::actingAs($user);
        $this->purchase($card->id, '2026-11-03', '1000');

        $novId = $this->getJson("/api/credit-cards/{$card->id}/statements")->json('data.1.id');

        $this->patchJson("/api/statements/{$novId}", ['interest_charged' => '50.50', 'fees_charged' => '100', 'min_payment' => '300'])
            ->assertOk()
            ->assertJsonPath('data.statement_amount', '1150.50')
            ->assertJsonPath('data.min_payment', '300.00');

        // ...and the next statement carries the new total.
        $this->getJson("/api/credit-cards/{$card->id}/statements")->assertJsonPath('data.0.carried_over', '1150.50');
    }

    public function test_a_card_installment_is_billed_in_the_statement_of_its_charge_date(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);

        $this->postJson('/api/installments', $this->planPayload($account, [
            'interest_method' => 'zero', 'annual_rate_percent' => '0', 'price' => '3000', 'term_count' => 3,
            'first_due_date' => '2026-11-20', 'funding_type' => 'credit_card',
            'credit_card_id' => $card->id, 'account_id' => null,
        ]))->assertCreated();

        // The first row (20 Nov) falls in the cycle 6 Nov - 5 Dec. The full price is NOT billed as a purchase.
        $this->getJson("/api/credit-cards/{$card->id}/statements")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.statement_date', '2026-12-05')
            ->assertJsonPath('data.0.installments_total', '1000.00')
            ->assertJsonPath('data.0.purchases_total', '0.00')
            ->assertJsonPath('data.0.statement_amount', '1000.00');
    }

    public function test_statements_of_other_users_are_not_reachable(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $card = $this->makeCard($owner, $this->makeAccount($owner));

        Sanctum::actingAs($this->makeUser('other@example.com'));
        $this->getJson("/api/credit-cards/{$card->id}/statements")->assertNotFound();
    }
}