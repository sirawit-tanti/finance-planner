<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->text('note')->nullable();
            $table->string('interest_method', 10);
            $table->decimal('annual_rate_percent', 9, 6)->default(0);
            $table->decimal('price', 14, 2);
            $table->decimal('down_payment', 14, 2)->default(0);
            $table->decimal('financed_amount', 14, 2);
            $table->unsignedSmallInteger('term_count');
            $table->string('frequency', 10);
            $table->date('start_date');
            $table->date('first_due_date');
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->string('fee_mode', 10)->default('none');
            $table->string('funding_type', 12);
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards');
            $table->foreignUuid('account_id')->nullable()->constrained('accounts');
            $table->decimal('total_interest', 14, 2)->default(0);  // locked at creation
            $table->decimal('total_payable', 14, 2);               // locked at creation
            $table->string('status', 12)->default('active');
        });
        $p = 'installment_plans';
        M::in($p, 'interest_method', ['zero', 'flat', 'reducing', 'effective']);
        M::in($p, 'frequency', ['monthly', 'biweekly', 'weekly', 'yearly']);
        M::in($p, 'fee_mode', ['none', 'upfront', 'spread']);
        M::in($p, 'funding_type', ['credit_card', 'account']);
        M::in($p, 'status', ['active', 'completed', 'cancelled', 'closed_early']);
        M::check($p, 'term_count', 'term_count BETWEEN 1 AND 360');
        M::positive($p, ['price', 'financed_amount']);
        M::nonNegative($p, ['down_payment', 'annual_rate_percent', 'fee_amount', 'total_interest']);
        M::check($p, 'financed_matches', 'financed_amount = price - down_payment');
        M::check($p, 'total_matches', 'total_payable = financed_amount + total_interest + fee_amount');
        M::check($p, 'zero_has_no_rate', "interest_method <> 'zero' OR annual_rate_percent = 0");
        M::check($p, 'fee_needs_mode', "fee_amount = 0 OR fee_mode <> 'none'");
        M::check($p, 'due_after_start', 'first_due_date >= start_date');
        M::check(
            $p,
            'funding_source',
            "(funding_type = 'credit_card' AND credit_card_id IS NOT NULL) OR (funding_type = 'account' AND account_id IS NOT NULL)"
        );

        Schema::create('installment_schedule_items', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no'); // 0 = upfront fee row
            $table->date('due_date');
            $table->decimal('principal_part', 14, 2);
            $table->decimal('interest_part', 14, 2);
            $table->decimal('fee_part', 14, 2);
            $table->decimal('total_due', 14, 2);
            $table->decimal('remaining_principal_after', 14, 2);
            $table->string('status', 10)->default('pending');
            $table->date('paid_date')->nullable();
            $table->foreignUuid('statement_id')->nullable()->constrained('credit_card_statements');
            $table->index(['plan_id', 'due_date']);
        });
        $s = 'installment_schedule_items';
        M::in($s, 'status', ['pending', 'paid', 'cancelled']);
        M::nonNegative($s, ['principal_part', 'interest_part', 'fee_part', 'total_due', 'remaining_principal_after']);
        M::check($s, 'total_matches', 'total_due = principal_part + interest_part + fee_part');
        M::check($s, 'paid_has_date', "status <> 'paid' OR paid_date IS NOT NULL");
        M::uniqueActive($s, 'schedule_plan_no_unique', ['plan_id', 'installment_no']);

        Schema::create('installment_payments', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->foreignUuid('schedule_item_id')->nullable()->constrained('installment_schedule_items');
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('kind', 14);
            $table->decimal('early_fee', 14, 2)->default(0);         // user-entered, never assumed
            $table->decimal('interest_discount', 14, 2)->default(0); // user-entered, never assumed
            $table->text('note')->nullable();
            $table->index(['plan_id', 'payment_date']);
        });
        M::in('installment_payments', 'kind', ['regular', 'prepay_partial', 'payoff']);
        M::positive('installment_payments', ['amount']);
        M::nonNegative('installment_payments', ['early_fee', 'interest_discount']);
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
        Schema::dropIfExists('installment_schedule_items');
        Schema::dropIfExists('installment_plans');
    }
};