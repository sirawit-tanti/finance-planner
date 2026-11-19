<?php

namespace Tests\Feature;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class CrudApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    private function categoryId($user, string $name = 'อาหาร'): string
    {
        return (string) Category::withoutGlobalScopes()->where('user_id', $user->id)->where('name', $name)->value('id');
    }

    public function test_account_crud_with_versioning_and_soft_delete(): void
    {
        Sanctum::actingAs($this->makeUser());

        $id = $this->postJson('/api/accounts', [
            'name' => 'ออมทรัพย์', 'type' => 'savings', 'opening_balance' => '5000', 'opening_date' => '2026-11-01',
        ])->assertCreated()
            ->assertJsonPath('data.opening_balance', '5000.00')
            ->assertJsonPath('data.opening_date', '2026-11-01')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.user_id')
            ->json('data.id');

        $this->getJson('/api/accounts')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/accounts/{$id}", [
            'name' => 'ชื่อใหม่', 'type' => 'cash', 'opening_balance' => '6000', 'opening_date' => '2026-11-01',
        ])->assertOk()->assertJsonPath('data.name', 'ชื่อใหม่')->assertJsonPath('data.version', 2);

        $this->deleteJson("/api/accounts/{$id}")->assertNoContent();
        $this->getJson("/api/accounts/{$id}")->assertNotFound();
        $this->assertDatabaseHas('accounts', ['id' => $id]); // kept as a tombstone for sync
    }

    public function test_other_users_rows_are_invisible_and_cannot_be_referenced(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $ownerAccount = $this->makeAccount($owner);

        $other = $this->makeUser('other@example.com');
        Sanctum::actingAs($other);

        $this->getJson("/api/accounts/{$ownerAccount->id}")->assertNotFound();
        $this->putJson("/api/accounts/{$ownerAccount->id}", [
            'name' => 'x', 'type' => 'cash', 'opening_date' => '2026-11-01',
        ])->assertNotFound();
        $this->getJson('/api/accounts/not-a-uuid')->assertNotFound();

        $this->postJson('/api/income-sources', [
            'name' => 'x', 'amount' => '100', 'frequency' => 'monthly', 'pay_day' => 1,
            'start_date' => '2026-01-01', 'account_id' => $ownerAccount->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }

    public function test_default_categories_exist_and_names_are_unique_per_kind(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/categories')->assertOk()->assertJsonCount(10, 'data');
        $this->postJson('/api/categories', ['name' => 'อาหาร', 'kind' => 'expense'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/categories', ['name' => 'กาแฟ', 'kind' => 'expense'])->assertCreated();
        $this->postJson('/api/categories', ['name' => 'อาหาร', 'kind' => 'income'])->assertCreated();
    }

    public function test_income_source_needs_the_schedule_field_of_its_frequency(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);
        $base = ['name' => 'เงินเดือน', 'amount' => '32000', 'start_date' => '2026-01-01', 'account_id' => $account->id];

        $this->postJson('/api/income-sources', $base + ['frequency' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('pay_day');
        $this->postJson('/api/income-sources', $base + ['frequency' => 'weekly'])
            ->assertUnprocessable()->assertJsonValidationErrors('weekday');
        $this->postJson('/api/income-sources', $base + ['frequency' => 'monthly', 'pay_day' => 25])
            ->assertCreated()->assertJsonPath('data.amount', '32000.00');
    }

    public function test_a_card_paid_recurring_expense_needs_a_card_and_others_need_an_account(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user, $account);
        Sanctum::actingAs($user);
        $base = [
            'name' => 'Netflix', 'category_id' => $this->categoryId($user, 'ความบันเทิง'), 'amount' => '419',
            'frequency' => 'monthly', 'due_day' => 5, 'start_date' => '2026-01-01',
        ];

        $this->postJson('/api/recurring-expenses', $base + ['payment_method' => 'credit_card'])
            ->assertUnprocessable()->assertJsonValidationErrors('credit_card_id');
        $this->postJson('/api/recurring-expenses', $base + ['payment_method' => 'transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/recurring-expenses', $base + ['payment_method' => 'credit_card', 'credit_card_id' => $card->id])
            ->assertCreated();
    }

    public function test_a_planned_item_flows_into_the_forecast(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/planned-items', [
            'kind' => 'expense', 'name' => 'ประกันรายปี', 'amount' => '3000',
            'planned_date' => '2026-12-01', 'account_id' => $account->id,
        ])->assertCreated();

        $this->getJson('/api/forecast?from=2026-11-19&months=2')
            ->assertJsonPath('data.months.1.expense_planned', '3000.00')
            ->assertJsonPath('data.months.1.closing_cash', '7000.00');
    }

    public function test_savings_goal_progress_and_contributions(): void
    {
        Sanctum::actingAs($this->makeUser());

        $goalId = $this->postJson('/api/savings-goals', [
            'name' => 'ทริปญี่ปุ่น', 'target_amount' => '60000', 'monthly_amount' => '5000', 'start_date' => '2026-11-01',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/savings-goals/{$goalId}/contributions", [
            'contribution_date' => '2026-11-10', 'amount' => '15000', 'status' => 'actual',
        ])->assertCreated();
        $this->postJson("/api/savings-goals/{$goalId}/contributions", [
            'contribution_date' => '2026-12-10', 'amount' => '5000', 'status' => 'planned',   // planned is not counted
        ])->assertCreated();
        $this->postJson("/api/savings-goals/{$goalId}/contributions", [
            'contribution_date' => '2026-12-10', 'amount' => '5000', 'status' => 'actual',     // actual cannot be in the future
        ])->assertUnprocessable()->assertJsonValidationErrors('contribution_date');

        $this->getJson("/api/savings-goals/{$goalId}")
            ->assertOk()
            ->assertJsonPath('data.saved_amount', '15000.00')
            ->assertJsonPath('data.progress_percent', '25.00')
            ->assertJsonPath('data.months_to_goal', 9)
            ->assertJsonPath('data.estimated_date', '2027-08-19');
        $this->getJson("/api/savings-goals/{$goalId}/contributions")->assertJsonCount(2, 'data');
    }

    public function test_budget_is_upserted_and_the_status_reports_the_level(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $food = $this->categoryId($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/budgets', ['category_id' => $food, 'amount' => '2000'])->assertCreated();
        $this->postJson('/api/budgets', ['category_id' => $food, 'amount' => '3000'])
            ->assertOk()->assertJsonPath('data.amount', '3000.00');
        $this->getJson('/api/budgets')->assertJsonCount(1, 'data');

        $spend = fn (string $amount) => $this->postJson('/api/transactions', [
            'type' => 'expense', 'txn_date' => '2026-11-10', 'name' => 'อาหาร', 'amount' => $amount,
            'payment_method' => 'cash', 'account_id' => $account->id, 'category_id' => $food,
        ])->assertCreated();

        $spend('600');
        $this->getJson('/api/budgets/status?month=2026-11')
            ->assertOk()
            ->assertJsonPath('data.items.0.budget', '3000.00')
            ->assertJsonPath('data.items.0.spent', '600.00')
            ->assertJsonPath('data.items.0.remaining', '2400.00')
            ->assertJsonPath('data.items.0.percent', '20.00')
            ->assertJsonPath('data.items.0.level', 'ok');

        $spend('2100');   // 2,700 = 90%
        $this->getJson('/api/budgets/status?month=2026-11')->assertJsonPath('data.items.0.level', 'warn');

        $spend('500');    // 3,200 = over
        $this->getJson('/api/budgets/status?month=2026-11')
            ->assertJsonPath('data.items.0.level', 'over')
            ->assertJsonPath('data.items.0.percent', '106.67');

        $this->getJson('/api/budgets/status?month=2026-10')->assertJsonPath('data.items.0.spent', '0.00');
    }

    public function test_a_credit_card_and_a_scenario_can_be_saved(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/credit-cards', [
            'name' => 'บัตร B', 'bank' => 'SCB', 'credit_limit' => '80000', 'statement_day' => 10,
            'due_day' => 28, 'pay_account_id' => $account->id,
        ])->assertCreated()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/credit-cards', [
            'name' => 'x', 'bank' => 'y', 'statement_day' => 32, 'due_day' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('statement_day');

        $this->postJson('/api/scenarios', [
            'name' => 'ซื้อโทรศัพท์',
            'changes' => [['type' => 'add_one_off', 'kind' => 'expense', 'name' => 'โทรศัพท์', 'amount' => '30000', 'date' => '2026-12-12']],
        ])->assertCreated()->assertJsonPath('data.changes.0.type', 'add_one_off');
        $this->getJson('/api/scenarios')->assertJsonCount(1, 'data');
    }
}