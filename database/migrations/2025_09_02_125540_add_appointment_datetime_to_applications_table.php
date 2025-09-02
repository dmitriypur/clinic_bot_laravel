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
            $table->datetime('appointment_datetime')->nullable()->after('doctor_id')->comment('Дата и время приема');
            $table->integer('cabinet_id')->nullable()->after('appointment_datetime')->comment('ID кабинета для приема');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['appointment_datetime', 'cabinet_id']);
        });
    }
};
