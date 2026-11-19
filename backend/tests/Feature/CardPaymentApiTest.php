<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class CardPaymentApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    private string $accountId;
    private string $cardId;
    private string $novStatementId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');

        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);

        $this->postJson('/api/transactions', [
            'type' => 'card_purchase', 'txn_date' => '2026-11-03', 'name' => 'ซื้อของ',
            'amount' => '1000', 'credit_card_id' => $card->id,
        ])->assertCreated();

        $this->accountId = (string) $account->id;
        $this->cardId = (string) $card->id;
        $this->novStatementId = $this->getJson("/api/credit-cards/{$card->id}/statements")->json('data.1.id');
    }

    private function pay(string $amount, string $date = '2026-11-19')
    {
        return $this->postJson("/api/statements/{$this->novStatementId}/payments", [
            'account_id' => $this->accountId, 'payment_date' => $date, 'amount' => $amount,
        ]);
    }

    public function test_a_partial_payment_reduces_the_bill_the_carried_amount_and_the_cash(): void
    {
        $this->pay('300')->assertCreated()
            ->assertJsonPath('data.kind', 'partial')
            ->assertJsonPath('statement.paid_amount', '300.00')
            ->assertJsonPath('statement.outstanding', '700.00')
            ->assertJsonPath('statement.status', 'partial');

        $this->getJson("/api/credit-cards/{$this->cardId}/statements")
            ->assertJsonPath('data.0.carried_over', '700.00')
            ->assertJsonPath('data.0.statement_amount', '700.00');

        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '9700.00');
    }

    public function test_paying_in_full_marks_the_statement_paid(): void
    {
        $this->pay('1000')->assertCreated()
            ->assertJsonPath('data.kind', 'full')
            ->assertJsonPath('statement.status', 'paid')
            ->assertJsonPath('statement.outstanding', '0.00');
    }

    public function test_paying_exactly_the_minimum_is_labelled_minimum(): void
    {
        $this->patchJson("/api/statements/{$this->novStatementId}", ['min_payment' => '100'])->assertOk();

        $this->pay('100')->assertCreated()->assertJsonPath('data.kind', 'minimum');
    }

    public function test_overpaying_is_rejected(): void
    {
        $this->pay('1000.01')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay('0')->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_future_dated_payment_is_rejected(): void
    {
        $this->pay('100', '2026-11-20')->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }

    public function test_a_payment_after_the_next_statement_date_must_go_to_the_newer_statement(): void
    {
        Carbon::setTestNow('2026-12-20 12:00:00');

        $this->pay('100', '2026-12-10')->assertUnprocessable()->assertJsonValidationErrors('payment_date');
        $this->pay('100', '2026-12-05')->assertCreated();
    }

    public function test_deleting_a_payment_restores_the_bill(): void
    {
        $paymentId = $this->pay('300')->assertCreated()->json('data.id');

        $this->deleteJson("/api/card-payments/{$paymentId}")->assertNoContent();

        $this->getJson("/api/statements/{$this->novStatementId}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.outstanding', '1000.00')
            ->assertJsonPath('data.status', 'unpaid');
    }

    public function test_the_account_must_belong_to_the_user(): void
    {
        $strangerAccount = $this->makeAccount($this->makeUser('stranger@example.com'));

        $this->postJson("/api/statements/{$this->novStatementId}/payments", [
            'account_id' => $strangerAccount->id, 'payment_date' => '2026-11-19', 'amount' => '100',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }
}