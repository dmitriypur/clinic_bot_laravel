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
        // Индексы для таблицы applications (добавляем только недостающие)
        Schema::table('applications', function (Blueprint $table) {
            if (!Schema::hasIndex('applications', 'idx_appointment_datetime')) {
                $table->index('appointment_datetime', 'idx_appointment_datetime');
            }
            if (!Schema::hasIndex('applications', 'idx_cabinet_datetime')) {
                $table->index(['cabinet_id', 'appointment_datetime'], 'idx_cabinet_datetime');
            }
            if (!Schema::hasIndex('applications', 'idx_doctor_datetime')) {
                $table->index(['doctor_id', 'appointment_datetime'], 'idx_doctor_datetime');
            }
            if (!Schema::hasIndex('applications', 'idx_clinic_datetime')) {
                $table->index(['clinic_id', 'appointment_datetime'], 'idx_clinic_datetime');
            }
            if (!Schema::hasIndex('applications', 'idx_branch_datetime')) {
                $table->index(['branch_id', 'appointment_datetime'], 'idx_branch_datetime');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем индексы для таблицы applications
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_appointment_datetime');
            $table->dropIndexIfExists('idx_cabinet_datetime');
            $table->dropIndexIfExists('idx_doctor_datetime');
            $table->dropIndexIfExists('idx_clinic_datetime');
            $table->dropIndexIfExists('idx_branch_datetime');
        });
    }
};
