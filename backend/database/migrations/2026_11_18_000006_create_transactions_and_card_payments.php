<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('type', 16);
            $table->date('txn_date');
            $table->string('name');
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();
            $table->string('payment_method', 12);
            $table->foreignUuid('account_id')->nullable()->constrained('accounts');
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards');
            $table->foreignUuid('statement_id')->nullable()->constrained('credit_card_statements');
            $table->foreignUuid('installment_plan_id')->nullable()->constrained('installment_plans');
            $table->foreignUuid('recurring_expense_id')->nullable()->constrained('recurring_expenses');
            $table->foreignUuid('income_source_id')->nullable()->constrained('income_sources');
            $table->date('occurrence_date')->nullable();
            $table->index(['user_id', 'txn_date']);
            $table->index('statement_id');
            $table->index('credit_card_id');
        });
        $t = 'transactions';
        M::in($t, 'type', ['income', 'expense', 'card_purchase', 'card_refund']);
        M::in($t, 'payment_method', ['cash', 'transfer', 'debit', 'credit_card']);
        M::positive($t, ['amount']);
        // Card transactions touch a card, never an account (no cash leaves until the bill is paid).
        M::check(
            $t,
            'money_source',
            "(type IN ('card_purchase', 'card_refund') AND credit_card_id IS NOT NULL AND account_id IS NULL)"
            . " OR (type IN ('income', 'expense') AND account_id IS NOT NULL AND credit_card_id IS NULL)"
        );
        M::check($t, 'card_method', "(type IN ('card_purchase', 'card_refund')) = (payment_method = 'credit_card')");
        // Settles one occurrence of a recurring item: both ids set together with the date, never both kinds at once.
        M::check($t, 'one_recurring_kind', 'NOT (recurring_expense_id IS NOT NULL AND income_source_id IS NOT NULL)');
        M::check(
            $t,
            'occurrence_pair',
            '((recurring_expense_id IS NULL AND income_source_id IS NULL) = (occurrence_date IS NULL))'
        );
        // Stops the same occurrence being settled twice (offline double-submit, paying twice).
        M::uniqueActive(
            $t,
            'txn_expense_occurrence_unique',
            ['recurring_expense_id', 'occurrence_date'],
            'recurring_expense_id IS NOT NULL'
        );
        M::uniqueActive(
            $t,
            'txn_income_occurrence_unique',
            ['income_source_id', 'occurrence_date'],
            'income_source_id IS NOT NULL'
        );

        Schema::create('credit_card_payments', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('statement_id')->constrained('credit_card_statements');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('kind', 10);
            $table->text('note')->nullable();
            $table->index(['statement_id']);
        });
        M::in('credit_card_payments', 'kind', ['full', 'minimum', 'partial']);
        M::positive('credit_card_payments', ['amount']);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_payments');
        Schema::dropIfExists('transactions');
    }
};