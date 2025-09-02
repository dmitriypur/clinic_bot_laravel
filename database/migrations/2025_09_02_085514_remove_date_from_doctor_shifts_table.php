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
        Schema::table('doctor_shifts', function (Blueprint $table) {
            // Удаляем старые индексы, которые используют поле date
            $table->dropIndex('idx_cabinet_date');
            $table->dropIndex('idx_doctor_date');
            $table->dropUnique('unique_doctor_shift');
            
            // Удаляем поле date
            $table->dropColumn('date');
            
            // Создаем новые индексы без поля date
            $table->index(['cabinet_id', 'start_time'], 'idx_cabinet_start_time');
            $table->index(['doctor_id', 'start_time'], 'idx_doctor_start_time');
            $table->unique(['doctor_id', 'cabinet_id', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_shifts', function (Blueprint $table) {
            // Удаляем новые индексы
            $table->dropIndex('idx_cabinet_start_time');
            $table->dropIndex('idx_doctor_start_time');
            $table->dropUnique('unique_doctor_shift');
            
            // Восстанавливаем поле date
            $table->date('date');
            
            // Восстанавливаем старые индексы
            $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');
            $table->index(['doctor_id', 'date'], 'idx_doctor_date');
            $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }
};