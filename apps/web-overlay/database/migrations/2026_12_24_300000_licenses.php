<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 27.1c: Enterprise licences (phase27g.sql). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase27g.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase27g.down.sql')));
    }
};
