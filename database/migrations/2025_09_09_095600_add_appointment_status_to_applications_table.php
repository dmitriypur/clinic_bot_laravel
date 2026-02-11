<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('appointment_status', ['scheduled', 'in_progress', 'completed'])
                ->default('scheduled')
                ->after('send_to_1c')
                ->comment('Статус приема: scheduled - запланирован, in_progress - в процессе, completed - завершен');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('appointment_status');
        });
    }
};
