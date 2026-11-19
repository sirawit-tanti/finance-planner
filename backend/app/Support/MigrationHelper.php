<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Helpers used only by migrations. */
final class MigrationHelper
{
    /**
     * Columns shared by every syncable table (see Phase 2, section 3.1).
     * server_seq has a DB-level default so even a raw INSERT gets a sequence number.
     */
    public static function syncColumns(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
        $table->unsignedInteger('version')->default(1);
        $table->unsignedBigInteger('server_seq')->default(DB::raw("nextval('sync_server_seq')"));
        $table->timestampsTz();
        $table->softDeletesTz();
        $table->index(['user_id', 'server_seq']);
    }

    /** Add a named CHECK constraint (names are unique per table, so keep them short). */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ck_{$name} CHECK ({$expression})");
    }

    /** CHECK (column IN (...)) used instead of PostgreSQL ENUM types (easier to change later). */
    public static function in(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));
        self::check($table, "{$column}_in", "{$column} IN ({$list})");
    }

    /** @param list<string> $columns */
    public static function nonNegative(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            self::check($table, "{$column}_nonneg", "{$column} >= 0");
        }
    }

    /** @param list<string> $columns */
    public static function positive(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            self::check($table, "{$column}_pos", "{$column} > 0");
        }
    }

    /**
     * Unique index that ignores soft-deleted rows, so a deleted record
     * does not block creating a new one with the same key.
     *
     * @param list<string> $columns
     */
    public static function uniqueActive(string $table, string $indexName, array $columns, ?string $extraWhere = null): void
    {
        $where = 'deleted_at IS NULL' . ($extraWhere !== null ? " AND {$extraWhere}" : '');
        DB::statement(
            "CREATE UNIQUE INDEX {$indexName} ON {$table} (" . implode(', ', $columns) . ") WHERE {$where}"
        );
    }
}