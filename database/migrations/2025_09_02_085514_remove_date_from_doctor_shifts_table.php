<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция для удаления поля date из таблицы смен врачей
 * 
 * Удаляет избыточное поле date, так как дата уже содержится в полях start_time и end_time.
 * Обновляет индексы и уникальные ограничения для работы без поля date.
 * Это упрощает структуру таблицы и предотвращает дублирование данных.
 */
return new class extends Migration
{
    /**
     * Удаление поля date и обновление индексов
     */
    public function up(): void
    {
        Schema::table('doctor_shifts', function (Blueprint $table) {
            // Сначала удаляем foreign key constraints для возможности изменения структуры
            $table->dropForeign(['cabinet_id']);
            $table->dropForeign(['doctor_id']);
            
            // Удаляем старые индексы, которые включают поле date
            $table->dropIndex('idx_cabinet_date');
            $table->dropIndex('idx_doctor_date');
            $table->dropUnique('unique_doctor_shift');
            
            // Удаляем избыточное поле date
            $table->dropColumn('date');
            
            // Восстанавливаем foreign key constraints
            $table->foreign('cabinet_id')->references('id')->on('cabinets')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
            
            // Создаем новые индексы без поля date
            $table->index(['cabinet_id', 'start_time'], 'idx_cabinet_start_time');  // Поиск по кабинету и времени
            $table->index(['doctor_id', 'start_time'], 'idx_doctor_start_time');    // Поиск по врачу и времени
            
            // Новое уникальное ограничение без поля date
            $table->unique(['doctor_id', 'cabinet_id', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }

    /**
     * Откат миграции - восстановление поля date и старых индексов
     */
    public function down(): void
    {
        Schema::table('doctor_shifts', function (Blueprint $table) {
            // Удаляем новые индексы (если они существуют)
            try {
                $table->dropIndex('idx_cabinet_start_time');
            } catch (\Exception $e) {
                // Индекс может не существовать
            }
            
            try {
                $table->dropIndex('idx_doctor_start_time');
            } catch (\Exception $e) {
                // Индекс может не существовать
            }
            
            try {
                $table->dropUnique('unique_doctor_shift');
            } catch (\Exception $e) {
                // Уникальное ограничение может не существовать
            }
            
            // Удаляем foreign key constraints для возможности изменения структуры
            $table->dropForeign(['cabinet_id']);
            $table->dropForeign(['doctor_id']);
            
            // Восстанавливаем поле date с значением по умолчанию
            $table->date('date')->default('2025-01-01');
            
            // Восстанавливаем foreign key constraints
            $table->foreign('cabinet_id')->references('id')->on('cabinets')->onDelete('cascade');
            $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
            
            // Восстанавливаем старые индексы с полем date
            $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');
            $table->index(['doctor_id', 'date'], 'idx_doctor_date');
            $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }
};