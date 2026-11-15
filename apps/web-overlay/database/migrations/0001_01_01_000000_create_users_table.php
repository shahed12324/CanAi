<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Replaces the skeleton's users migration (same filename, so it overwrites it).
 * Users use ULID keys; tenancy + RLS live in plain SQL that is tested against a
 * real PostgreSQL (tests/db). Requires database/sql/roles.sql to have been run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase2.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase2.down.sql')));
    }
};
