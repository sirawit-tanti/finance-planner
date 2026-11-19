<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class InstallmentPaymentApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    private string $planId;
    private string $firstRowId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');

        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $plan = $this->postJson('/api/installments', $this->planPayload($account))->assertCreated();
        $this->planId = $plan->json('data.id');
        $this->firstRowId = $plan->json('data.schedule.0.id');
    }

    public function test_the_full_price_purchase_is_recorded_but_does_not_reduce_cash(): void
    {
        $this->assertDatabaseHas('transactions', ['installment_plan_id' => $this->planId, 'amount' => '30000.00', 'type' => 'expense']);

        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '10000.00');
    }

    public function test_paying_one_installment_marks_it_paid_and_reduces_cash(): void
    {
        $this->postJson("/api/installments/{$this->planId}/payments", [
            'schedule_item_id' => $this->firstRowId, 'payment_date' => '2026-11-19',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '2800.00')
            ->assertJsonPath('data.kind', 'regular')
            ->assertJsonPath('plan_status', 'active');

        $this->getJson("/api/installments/{$this->planId}")->assertJsonPath('data.schedule.0.status', 'paid');
        $this->getJson('/api/forecast?from=2026-11-19&months=1')->assertJsonPath('data.opening_cash', '7200.00');
    }

    public function test_the_same_installment_cannot_be_paid_twice(): void
    {
        $body = ['schedule_item_id' => $this->firstRowId, 'payment_date' => '2026-11-19'];

        $this->postJson("/api/installments/{$this->planId}/payments", $body)->assertCreated();
        $this->postJson("/api/installments/{$this->planId}/payments", $body)
            ->assertUnprocessable()->assertJsonValidationErrors('schedule_item_id');
    }

    public function test_the_payoff_quote_uses_the_user_entered_discount_and_fee(): void
    {
        $this->postJson("/api/installments/{$this->planId}/payments", [
            'schedule_item_id' => $this->firstRowId, 'payment_date' => '2026-11-19',
        ])->assertCreated();

        // 11 rows left: 27,500 principal + 3,300 interest = 30,800
        $this->getJson("/api/installments/{$this->planId}/payoff-quote")
            ->assertOk()
            ->assertJsonPath('data.payoff_amount', '30800.00')   // no discount is ever assumed
            ->assertJsonPath('data.remaining_installments', 11);

        $this->getJson("/api/installments/{$this->planId}/payoff-quote?interest_discount=1000&early_fee=500")
            ->assertOk()
            ->assertJsonPath('data.remaining_principal', '27500.00')
            ->assertJsonPath('data.remaining_interest', '3300.00')
            ->assertJsonPath('data.scheduled_remaining', '30800.00')
            ->assertJsonPath('data.payoff_amount', '30300.00');
    }

    public function test_paying_off_closes_the_plan_and_takes_the_cash(): void
    {
        $this->postJson("/api/installments/{$this->planId}/payments", [
            'schedule_item_id' => $this->firstRowId, 'payment_date' => '2026-11-19',
        ])->assertCreated();

        $this->postJson("/api/installments/{$this->planId}/payoff", [
            'payment_date' => '2026-11-19', 'interest_discount' => '1000', 'early_fee' => '500',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '30300.00')
            ->assertJsonPath('data.kind', 'payoff')
            ->assertJsonPath('data.interest_discount', '1000.00')
            ->assertJsonPath('plan_status', 'closed_early');

        // 10,000 - 2,800 - 30,300
        $this->getJson('/api/forecast?from=2026-11-19&months=1')
            ->assertJsonPath('data.opening_cash', '-23100.00')
            ->assertJsonPath('data.months.0.installments_total', '0.00');
    }

    public function test_a_discount_larger_than_the_remaining_interest_is_rejected(): void
    {
        $this->postJson("/api/installments/{$this->planId}/payoff", [
            'payment_date' => '2026-11-19', 'interest_discount' => '3600.01',
        ])->assertUnprocessable()->assertJsonValidationErrors('interest_discount');
    }

    public function test_a_card_plan_has_no_separate_payments(): void
    {
        $user = $this->makeUser('cardplan@example.com');
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);

        $planId = $this->postJson('/api/installments', $this->planPayload($account, [
            'interest_method' => 'zero', 'annual_rate_percent' => '0', 'price' => '3000', 'term_count' => 3,
            'funding_type' => 'credit_card', 'credit_card_id' => $card->id, 'account_id' => null,
        ]))->assertCreated()->json('data.id');

        $this->getJson("/api/installments/{$planId}/payoff-quote")->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_cancelling_removes_the_future_installments_from_the_forecast(): void
    {
        $this->deleteJson("/api/installments/{$this->planId}")
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.schedule.0.status', 'cancelled');

        $this->getJson('/api/forecast?from=2026-11-19&months=3')
            ->assertJsonPath('data.summary.total_installments', '0.00');
    }
}