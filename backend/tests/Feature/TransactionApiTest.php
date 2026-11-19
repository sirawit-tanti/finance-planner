<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    private function expense(string $accountId, array $override = []): array
    {
        return array_merge([
            'type' => 'expense', 'txn_date' => '2026-11-10', 'name' => 'ข้าว', 'amount' => '120.50',
            'payment_method' => 'cash', 'account_id' => $accountId,
        ], $override);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }

    public function test_creating_an_expense_reduces_the_cash_in_the_forecast(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/transactions', $this->expense($account->id))
            ->assertCreated()
            ->assertJsonPath('data.amount', '120.50')
            ->assertJsonPath('data.txn_date', '2026-11-10');

        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '9879.50');
    }

    public function test_validation_rules(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);

        $this->postJson('/api/transactions', $this->expense($account->id, ['txn_date' => '2026-11-20']))
            ->assertUnprocessable()->assertJsonValidationErrors('txn_date');
        $this->postJson('/api/transactions', $this->expense($account->id, ['amount' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/transactions', $this->expense($account->id, ['amount' => '10.001']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        // A card transaction must not name an account; a cash one must not name a card.
        $this->postJson('/api/transactions', [
            'type' => 'card_purchase', 'txn_date' => '2026-11-10', 'name' => 'x', 'amount' => '10',
            'credit_card_id' => $card->id, 'account_id' => $account->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/transactions', $this->expense($account->id, ['credit_card_id' => $card->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('credit_card_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_listing_filters_by_month_and_category(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/transactions', $this->expense($account->id))->assertCreated();
        $this->postJson('/api/transactions', $this->expense($account->id, ['txn_date' => '2026-10-15']))->assertCreated();

        $this->getJson('/api/transactions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/transactions?month=2026-11')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/transactions?month=2026-09')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/transactions?payment_method=transfer')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_update_and_delete_a_cash_transaction(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/transactions', $this->expense($account->id))->json('data.id');

        $this->putJson("/api/transactions/{$id}", $this->expense($account->id, ['amount' => '200']))
            ->assertOk()->assertJsonPath('data.amount', '200.00');
        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '9800.00');

        $this->deleteJson("/api/transactions/{$id}")->assertNoContent();
        $this->getJson("/api/transactions/{$id}")->assertNotFound();
    }

    public function test_moving_a_card_purchase_to_another_date_moves_it_between_statements(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard($user, $this->makeAccount($user));
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/transactions', [
            'type' => 'card_purchase', 'txn_date' => '2026-11-03', 'name' => 'x', 'amount' => '1000', 'credit_card_id' => $card->id,
        ])->json('data.id');

        $this->putJson("/api/transactions/{$id}", [
            'type' => 'card_purchase', 'txn_date' => '2026-11-06', 'name' => 'x', 'amount' => '1000', 'credit_card_id' => $card->id,
        ])->assertOk();

        $this->getJson("/api/credit-cards/{$card->id}/statements")
            ->assertJsonPath('data.0.purchases_total', '1000.00')   // 5 Dec statement
            ->assertJsonPath('data.1.purchases_total', '0.00');     // 5 Nov statement
    }

    public function test_another_users_transaction_is_not_reachable(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $account = $this->makeAccount($owner);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/transactions', $this->expense($account->id))->json('data.id');

        Sanctum::actingAs($this->makeUser('other@example.com'));
        $this->getJson("/api/transactions/{$id}")->assertNotFound();
        $this->deleteJson("/api/transactions/{$id}")->assertNotFound();
    }
}