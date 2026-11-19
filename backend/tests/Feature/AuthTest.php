<?php

namespace Tests\Feature;

use App\Application\Auth\RegisterUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'สมชาย',
            'email' => 'Somchai@Example.com',
            'password' => 'password123',
        ], $override);
    }

    public function test_register_creates_user_settings_default_categories_and_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'data_version'], 'token'])
            ->assertJsonPath('user.email', 'somchai@example.com'); // lower-cased

        $userId = $response->json('user.id');

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $userId, 'currency' => 'THB', 'month_start_day' => 1, 'locale_year' => 'BE',
        ]);
        $this->assertSame(
            count(RegisterUser::DEFAULT_EXPENSE_CATEGORIES),
            DB::table('categories')->where('user_id', $userId)->where('kind', 'expense')->count()
        );
        $this->assertNotSame('password123', User::query()->first()->password); // stored hashed
    }

    public function test_register_rejects_a_duplicate_email_ignoring_case(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/auth/register', $this->payload(['email' => 'SOMCHAI@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_register_rejects_a_short_password(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['password' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_login_returns_a_token_that_opens_the_me_endpoint(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'SOMCHAI@example.com', 'password' => 'password123',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'somchai@example.com');
    }

    public function test_login_with_a_wrong_password_is_a_validation_error_not_401(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'somchai@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_me_without_a_token_is_401_json(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/auth/register', $this->payload())->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_repeated_login_attempts_are_rate_limited(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'somchai@example.com', 'password' => 'bad-password'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'somchai@example.com', 'password' => 'bad-password'])
            ->assertStatus(429);
    }
}