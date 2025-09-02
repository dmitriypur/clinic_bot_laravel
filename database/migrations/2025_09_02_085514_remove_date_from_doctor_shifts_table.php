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
            // Сначала удаляем foreign key constraints
            $table->dropForeign(['cabinet_id']);
            $table->dropForeign(['doctor_id']);
            
            // Затем удаляем индексы
            $table->dropIndex('idx_cabinet_date');
            $table->dropIndex('idx_doctor_date');
            $table->dropUnique('unique_doctor_shift');
            
            // Удаляем поле date
            $table->dropColumn('date');
            
            // Восстанавливаем foreign key constraints
            $table->foreign('cabinet_id')->references('id')->on('cabinets')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
            
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
            
            // Удаляем foreign key constraints
            $table->dropForeign(['cabinet_id']);
            $table->dropForeign(['doctor_id']);
            
            // Восстанавливаем поле date с значением по умолчанию
            $table->date('date')->default('2025-01-01');
            
            // Восстанавливаем foreign key constraints
            $table->foreign('cabinet_id')->references('id')->on('cabinets')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
            
            // Восстанавливаем старые индексы
            $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');
            $table->index(['doctor_id', 'date'], 'idx_doctor_date');
            $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }
};