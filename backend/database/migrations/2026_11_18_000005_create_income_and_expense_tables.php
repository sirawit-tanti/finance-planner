<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FREQUENCIES = ['monthly', 'weekly', 'biweekly', 'yearly'];
    private const SHIFT_RULES = ['none', 'previous_weekday', 'next_weekday'];

    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->string('frequency', 10);
            $table->unsignedSmallInteger('pay_day')->nullable(); // monthly / yearly
            $table->unsignedSmallInteger('weekday')->nullable(); // weekly / biweekly, 1 = Monday ... 7 = Sunday
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('shift_rule', 20)->default('none');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->boolean('is_active')->default(true);
        });
        $i = 'income_sources';
        M::in($i, 'frequency', self::FREQUENCIES);
        M::in($i, 'shift_rule', self::SHIFT_RULES);
        M::nonNegative($i, ['amount']);
        M::check($i, 'pay_day', 'pay_day BETWEEN 1 AND 31');
        M::check($i, 'weekday', 'weekday BETWEEN 1 AND 7');
        M::check($i, 'end_after_start', 'end_date IS NULL OR end_date >= start_date');
        M::check(
            $i,
            'schedule_fields',
            "(frequency IN ('weekly', 'biweekly') AND weekday IS NOT NULL) OR (frequency IN ('monthly', 'yearly') AND pay_day IS NOT NULL)"
        );

        Schema::create('recurring_expenses', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->foreignUuid('category_id')->constrained('categories');
            $table->decimal('amount', 14, 2);
            $table->boolean('is_variable')->default(false);
            $table->string('frequency', 10);
            $table->unsignedSmallInteger('due_day')->nullable();
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('payment_method', 12);
            $table->foreignUuid('account_id')->nullable()->constrained('accounts');
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards');
            $table->string('shift_rule', 20)->default('none');
            $table->text('note')->nullable();
        });
        $e = 'recurring_expenses';
        M::in($e, 'frequency', self::FREQUENCIES);
        M::in($e, 'shift_rule', self::SHIFT_RULES);
        M::in($e, 'payment_method', ['cash', 'transfer', 'credit_card', 'auto_debit']);
        M::nonNegative($e, ['amount']);
        M::check($e, 'due_day', 'due_day BETWEEN 1 AND 31');
        M::check($e, 'weekday', 'weekday BETWEEN 1 AND 7');
        M::check($e, 'end_after_start', 'end_date IS NULL OR end_date >= start_date');
        M::check(
            $e,
            'schedule_fields',
            "(frequency IN ('weekly', 'biweekly') AND weekday IS NOT NULL) OR (frequency IN ('monthly', 'yearly') AND due_day IS NOT NULL)"
        );
        M::check(
            $e,
            'pay_source',
            "(payment_method = 'credit_card' AND credit_card_id IS NOT NULL) OR (payment_method <> 'credit_card' AND account_id IS NOT NULL)"
        );

        Schema::create('recurring_overrides', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('source_type', 10);
            $table->uuid('source_id'); // points to income_sources OR recurring_expenses, so no FK is possible
            $table->date('occurrence_date');
            $table->decimal('override_amount', 14, 2)->nullable();
            $table->date('override_date')->nullable();
            $table->boolean('is_skipped')->default(false);
        });
        M::in('recurring_overrides', 'source_type', ['income', 'expense']);
        M::nonNegative('recurring_overrides', ['override_amount']);
        M::uniqueActive(
            'recurring_overrides',
            'overrides_occurrence_unique',
            ['user_id', 'source_type', 'source_id', 'occurrence_date']
        );

        Schema::create('planned_items', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('kind', 10);
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->date('planned_date');
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->string('status', 10)->default('planned');
            $table->index(['user_id', 'planned_date']);
        });
        M::in('planned_items', 'kind', ['income', 'expense']);
        M::in('planned_items', 'status', ['planned', 'done', 'cancelled']);
        M::positive('planned_items', ['amount']);
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_items');
        Schema::dropIfExists('recurring_overrides');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('income_sources');
    }
};