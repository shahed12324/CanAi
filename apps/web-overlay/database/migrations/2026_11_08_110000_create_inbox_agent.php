<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Phase 13b: channel secrets + delivery (phase13b.sql). Phase 13c: AI support agent (phase13c.sql). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase13b.up.sql')));
        DB::unprepared(file_get_contents(database_path('sql/phase13c.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase13c.down.sql')));
        DB::unprepared(file_get_contents(database_path('sql/phase13b.down.sql')));
    }
};
