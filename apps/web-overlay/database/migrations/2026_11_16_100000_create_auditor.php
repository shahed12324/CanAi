<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 18a: the auditor - findings, thresholds, monthly checklist, health checks (phase18a.sql). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase18a.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase18a.down.sql')));
    }
};
