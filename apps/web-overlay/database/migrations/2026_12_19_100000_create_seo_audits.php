<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 24.3c: SEO audits (phase243c.sql). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase243c.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase243c.down.sql')));
    }
};
