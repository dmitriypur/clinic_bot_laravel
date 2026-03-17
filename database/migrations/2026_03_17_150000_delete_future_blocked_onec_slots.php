<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('onec_slots')
            ->where('status', 'blocked')
            ->where('start_at', '>=', '2026-03-31 00:00:00')
            ->delete();
    }

    public function down(): void
    {
        // Одноразовая очистка некорректных блокировок не откатывается.
    }
};
