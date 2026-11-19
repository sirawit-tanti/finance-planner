<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncableModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'a@example.com'): User
    {
        return User::create(['name' => 'Tester', 'email' => $email, 'password' => 'password123']);
    }

    private function makeAccount(User $user, string $name = 'บัญชีหลัก'): Account
    {
        $account = new Account();
        $account->forceFill([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'savings',
            'opening_balance' => '1000.00',
            'opening_date' => '2026-11-01',
        ])->save();

        return $account;
    }

    private function makeCard(User $user): CreditCard
    {
        $card = new CreditCard();
        $card->forceFill([
            'user_id' => $user->id,
            'name' => 'บัตร A',
            'bank' => 'KBank',
            'credit_limit' => '50000.00',
            'statement_day' => 5,
            'due_day' => 25,
        ])->save();

        return $card;
    }

    public function test_create_sets_version_one_and_an_increasing_server_seq(): void
    {
        $user = $this->makeUser();
        $first = $this->makeAccount($user, 'A');
        $second = $this->makeAccount($user, 'B');

        $this->assertSame(1, $first->version);
        $this->assertSame(1, $second->version);
        $this->assertGreaterThan($first->server_seq, $second->server_seq);
    }

    public function test_update_bumps_version_and_server_seq(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $oldSeq = $account->server_seq;

        $account->name = 'ชื่อใหม่';
        $account->save();

        $this->assertSame(2, $account->version);
        $this->assertGreaterThan($oldSeq, $account->server_seq);
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'version' => 2, 'name' => 'ชื่อใหม่']);
    }

    public function test_soft_delete_leaves_a_tombstone_with_a_new_server_seq(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $oldSeq = $account->server_seq;
        Sanctum::actingAs($user);

        $account->delete();

        $row = Account::withTrashed()->find($account->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(2, $row->version);
        $this->assertGreaterThan($oldSeq, $row->server_seq);
        $this->assertSame(0, Account::count()); // hidden from normal queries
    }

    public function test_data_version_increases_on_create_update_and_delete(): void
    {
        $user = $this->makeUser();
        $this->assertSame(0, (int) $user->fresh()->data_version);

        $account = $this->makeAccount($user);
        $this->assertSame(1, (int) $user->fresh()->data_version);

        $account->name = 'x';
        $account->save();
        $this->assertSame(2, (int) $user->fresh()->data_version);

        $account->delete();
        $this->assertSame(3, (int) $user->fresh()->data_version);
    }

    public function test_users_cannot_see_each_others_rows(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $aliceAccount = $this->makeAccount($alice, 'ของ Alice');
        $bobAccount = $this->makeAccount($bob, 'ของ Bob');

        Sanctum::actingAs($alice);
        $this->assertSame(1, Account::count());
        $this->assertNotNull(Account::find($aliceAccount->id));
        $this->assertNull(Account::find($bobAccount->id));

        Sanctum::actingAs($bob);
        $this->assertSame(1, Account::count());
        $this->assertNull(Account::find($aliceAccount->id));
    }

    public function test_queries_return_nothing_when_nobody_is_logged_in(): void
    {
        $this->makeAccount($this->makeUser());

        $this->assertSame(0, Account::count());                          // fail closed
        $this->assertSame(1, Account::withoutGlobalScopes()->count());   // the row really exists
    }

    public function test_a_client_supplied_uuid_is_kept(): void
    {
        $user = $this->makeUser();
        $id = (string) Str::uuid();

        $account = new Account();
        $account->forceFill([
            'id' => $id, 'user_id' => $user->id, 'name' => 'Offline', 'type' => 'cash',
            'opening_date' => '2026-11-01',
        ])->save();

        $this->assertDatabaseHas('accounts', ['id' => $id]);
    }

    public function test_an_invalid_account_type_is_rejected_by_the_database(): void
    {
        $user = $this->makeUser();

        $this->expectException(QueryException::class);
        (new Account())->forceFill([
            'user_id' => $user->id, 'name' => 'x', 'type' => 'bogus', 'opening_date' => '2026-11-01',
        ])->save();
    }

    public function test_a_card_purchase_is_accepted_when_it_only_references_a_card(): void
    {
        $user = $this->makeUser();
        $card = $this->makeCard($user);

        DB::table('transactions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'card_purchase',
            'txn_date' => '2026-11-03', 'name' => 'ซื้อของ', 'amount' => '100.00',
            'payment_method' => 'credit_card', 'credit_card_id' => $card->id,
        ]);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_card_purchase_that_also_references_an_account_is_rejected(): void
    {
        $user = $this->makeUser();
        $account = $this->makeAccount($user);
        $card = $this->makeCard($user);

        $this->expectException(QueryException::class);
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => 'card_purchase',
            'txn_date' => '2026-11-03', 'name' => 'ซื้อของ', 'amount' => '100.00',
            'payment_method' => 'credit_card', 'credit_card_id' => $card->id, 'account_id' => $account->id,
        ]);
    }
}