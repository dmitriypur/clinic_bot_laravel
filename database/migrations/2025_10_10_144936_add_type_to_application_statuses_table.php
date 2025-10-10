<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('application_statuses', function (Blueprint $table) {
            $table->string('type', 30)->default('bid');
        });

        DB::table('application_statuses')
            ->whereNull('type')
            ->orWhere('type', '')
            ->update(['type' => 'bid']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_statuses', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
