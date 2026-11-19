<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase, FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    public function test_defaults_are_created_at_registration(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.month_start_day', 1)
            ->assertJsonPath('data.emergency_fund_amount', '0.00')
            ->assertJsonPath('data.forecast_default_months', 12)
            ->assertJsonPath('data.locale_year', 'BE');
    }

    public function test_updating_settings_changes_the_forecast_months(): void
    {
        $user = $this->makeUser();
        $this->makeAccount($user);
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', ['month_start_day' => 25, 'emergency_fund_amount' => '5000', 'forecast_default_months' => 6])
            ->assertOk()
            ->assertJsonPath('data.month_start_day', 25)
            ->assertJsonPath('data.emergency_fund_amount', '5000.00');

        // From 19 Nov the financial month started on 25 Oct, and the horizon is now 6 months.
        $this->getJson('/api/forecast?from=2026-11-19')
            ->assertOk()
            ->assertJsonPath('data.months.0.key', '2026-10')
            ->assertJsonPath('data.horizon_months', 6)
            ->assertJsonPath('data.emergency_fund', '5000.00');
    }

    public function test_invalid_values_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson('/api/settings', ['month_start_day' => 29])->assertUnprocessable()->assertJsonValidationErrors('month_start_day');
        $this->putJson('/api/settings', ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->putJson('/api/settings', ['emergency_fund_amount' => '-5'])->assertUnprocessable()->assertJsonValidationErrors('emergency_fund_amount');
    }

    public function test_settings_are_private_to_each_user(): void
    {
        $alice = $this->makeUser('alice@example.com');
        Sanctum::actingAs($alice);
        $this->putJson('/api/settings', ['emergency_fund_amount' => '9000'])->assertOk();

        Sanctum::actingAs($this->makeUser('bob@example.com'));
        $this->getJson('/api/settings')->assertJsonPath('data.emergency_fund_amount', '0.00');
    }
}