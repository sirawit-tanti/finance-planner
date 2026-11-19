<?php

use App\Support\MigrationHelper as M;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->char('currency', 3)->default('THB');
            $table->unsignedSmallInteger('month_start_day')->default(1);
            $table->unsignedSmallInteger('default_salary_day')->nullable();
            $table->decimal('emergency_fund_amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('forecast_default_months')->default(12);
            $table->string('locale_year', 2)->default('BE'); // display only: BE or CE
            $table->timestampsTz();
        });
        M::check('user_settings', 'month_start_day', 'month_start_day BETWEEN 1 AND 28');
        M::check('user_settings', 'default_salary_day', 'default_salary_day BETWEEN 1 AND 31');
        M::check('user_settings', 'forecast_months', 'forecast_default_months BETWEEN 1 AND 36');
        M::nonNegative('user_settings', ['emergency_fund_amount']);
        M::in('user_settings', 'locale_year', ['BE', 'CE']);

        Schema::create('accounts', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->string('type', 10);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_date');
            $table->boolean('is_active')->default(true);
        });
        M::in('accounts', 'type', ['savings', 'cash', 'ewallet', 'other']);

        Schema::create('categories', function (Blueprint $table) {
            M::syncColumns($table);
            $table->string('name');
            $table->string('kind', 10);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
        M::in('categories', 'kind', ['expense', 'income']);
        M::uniqueActive('categories', 'categories_user_kind_name_unique', ['user_id', 'kind', 'name']);
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('user_settings');
    }
};