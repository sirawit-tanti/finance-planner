<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_free_to_spend_is_cash_minus_bills_before_the_next_salary_minus_the_emergency_fund(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $this->makeSalary($user, $account);
        $this->makeMortgage($user, $account);
        $this->setEmergencyFund($user, '3000.00');
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.as_of', '2026-11-19')
            ->assertJsonPath('data.cash_now', '10000.00')
            ->assertJsonPath('data.obligations_before_next_income.amount', '7700.00')   // the 20 Nov mortgage
            ->assertJsonPath('data.obligations_before_next_income.until', '2026-11-25')
            ->assertJsonPath('data.free_to_spend', '-700.00')                           // 10,000 - 7,700 - 3,000
            ->assertJsonPath('data.month.key', '2026-11')
            ->assertJsonPath('data.month.income', '32000.00')
            ->assertJsonPath('data.month.closing_cash', '34300.00')
            ->assertJsonPath('data.forecast.closing_after.1', '34300.00');
    }

    public function test_cards_show_the_issued_bill_and_its_due_date(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        $this->makeStatement($user, $card, '2026-11-05', '2026-11-25', '5000.00');
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cards.0.name', 'บัตร A')
            ->assertJsonPath('data.cards.0.issued.outstanding', '5000.00')
            ->assertJsonPath('data.cards.0.issued.due_date', '2026-11-25')
            ->assertJsonPath('data.cards.0.issued.is_overdue', false)
            ->assertJsonPath('data.cards.0.total_owed', '5000.00')
            ->assertJsonPath('data.cards.0.available_credit', '45000.00');
    }

    public function test_installments_show_what_is_left_and_when_the_debt_ends(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);
        $this->postJson('/api/installments', $this->planPayload($account))->assertCreated();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.installments.active_count', 1)
            ->assertJsonPath('data.installments.remaining_total', '33600.00')
            ->assertJsonPath('data.installments.debt_free_date', '2027-11-10')
            ->assertJsonPath('data.installments.items.0.remaining_installments', 12)
            ->assertJsonPath('data.installments.items.0.remaining_interest', '3600.00')
            ->assertJsonPath('data.installments.items.0.next_due_date', '2026-12-10')
            ->assertJsonPath('data.installments.items.0.next_amount', '2800.00');
    }

    public function test_an_empty_account_still_returns_a_complete_dashboard(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cash_now', '0.00')
            ->assertJsonPath('data.free_to_spend', '0.00')
            ->assertJsonPath('data.cards', [])
            ->assertJsonPath('data.installments.active_count', 0)
            ->assertJsonPath('data.installments.debt_free_date', null);
    }
}