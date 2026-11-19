<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Global, ever-increasing counter used as the sync cursor (server_seq).
        // IF NOT EXISTS: a standalone sequence survives migrate:fresh.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS sync_server_seq');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS sync_server_seq');
    }
};