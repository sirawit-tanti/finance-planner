<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->string('bank');
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->unsignedSmallInteger('statement_day');
            $table->unsignedSmallInteger('due_day');
            $table->foreignUuid('pay_account_id')->nullable()->constrained('accounts');
            $table->decimal('opening_balance', 14, 2)->default(0); // balance owed when the user started using the app
            $table->string('status', 10)->default('active');
        });
        M::check('credit_cards', 'statement_day', 'statement_day BETWEEN 1 AND 31');
        M::check('credit_cards', 'due_day', 'due_day BETWEEN 1 AND 31');
        M::nonNegative('credit_cards', ['credit_limit', 'opening_balance']);
        M::in('credit_cards', 'status', ['active', 'closed']);

        Schema::create('credit_card_statements', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('credit_card_id')->constrained('credit_cards');
            $table->date('cycle_start');
            $table->date('statement_date');
            $table->date('due_date');
            $table->decimal('purchases_total', 14, 2)->default(0);
            $table->decimal('installments_total', 14, 2)->default(0);
            $table->decimal('interest_charged', 14, 2)->default(0); // user-entered from the bill
            $table->decimal('fees_charged', 14, 2)->default(0);     // user-entered from the bill
            $table->decimal('refunds_total', 14, 2)->default(0);
            $table->decimal('carried_over', 14, 2)->default(0);
            $table->decimal('statement_amount', 14, 2)->default(0); // computed cache
            $table->decimal('min_payment', 14, 2)->default(0);      // user-entered
            $table->decimal('paid_amount', 14, 2)->default(0);      // sum of payments
            $table->string('status', 10)->default('unpaid');
            $table->index(['credit_card_id', 'due_date']);
        });
        M::in('credit_card_statements', 'status', ['unpaid', 'partial', 'paid']);
        M::nonNegative('credit_card_statements', [
            'purchases_total', 'installments_total', 'interest_charged', 'fees_charged',
            'refunds_total', 'carried_over', 'min_payment', 'paid_amount',
        ]);
        M::check('credit_card_statements', 'dates_order', 'cycle_start <= statement_date AND statement_date <= due_date');
        M::uniqueActive('credit_card_statements', 'statements_card_date_unique', ['credit_card_id', 'statement_date']);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statements');
        Schema::dropIfExists('credit_cards');
    }
};