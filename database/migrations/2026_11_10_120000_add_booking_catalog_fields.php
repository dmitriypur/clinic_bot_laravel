<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('specialty')->nullable();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });

        Schema::table('onec_slots', function (Blueprint $table) {
            $table->index(['branch_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::table('onec_slots', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'start_at']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('specialty');
        });
    }
};
