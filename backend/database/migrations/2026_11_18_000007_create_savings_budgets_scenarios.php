<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->decimal('target_amount', 14, 2);
            $table->decimal('monthly_amount', 14, 2)->default(0);
            $table->date('start_date');
            $table->date('target_date')->nullable();
            $table->boolean('is_active')->default(true);
        });
        M::positive('savings_goals', ['target_amount']);
        M::nonNegative('savings_goals', ['monthly_amount']);

        Schema::create('savings_contributions', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('goal_id')->constrained('savings_goals')->cascadeOnDelete();
            $table->date('contribution_date');
            $table->decimal('amount', 14, 2);
            $table->string('status', 10)->default('planned');
        });
        M::in('savings_contributions', 'status', ['planned', 'actual']);
        M::positive('savings_contributions', ['amount']);

        Schema::create('budgets', function (Blueprint $table) {
            M::syncColumns($table);
            $table->foreignUuid('category_id')->constrained('categories');
            $table->date('month')->nullable(); // first day of the month; NULL = default budget for every month
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('warn_percent')->default(80);
        });
        M::nonNegative('budgets', ['amount']);
        M::check('budgets', 'warn_percent', 'warn_percent BETWEEN 1 AND 100');
        // NULL values are never equal in a unique index, so default and per-month budgets need separate indexes.
        M::uniqueActive('budgets', 'budgets_default_unique', ['user_id', 'category_id'], 'month IS NULL');
        M::uniqueActive('budgets', 'budgets_month_unique', ['user_id', 'category_id', 'month'], 'month IS NOT NULL');

        // What-if scenarios are online-only and never change real data, so they are not synced.
        Schema::create('scenarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('changes')->default(DB::raw("'[]'::jsonb"));
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('savings_contributions');
        Schema::dropIfExists('savings_goals');
    }
};