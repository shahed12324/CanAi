<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 14c: couriers + daily order report (phase14c.sql). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase14c.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase14c.down.sql')));
    }
};
