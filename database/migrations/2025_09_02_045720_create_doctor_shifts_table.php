<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция для создания таблицы смен врачей
 * 
 * Создает таблицу doctor_shifts для хранения информации о сменах врачей в кабинетах.
 * Каждая смена привязана к врачу и кабинету, имеет время начала/окончания и длительность слота.
 * Включает уникальные ограничения для предотвращения конфликтов расписания.
 */
return new class extends Migration
{
    /**
     * Создание таблицы смен врачей
     */
    public function up(): void
    {
        Schema::create('doctor_shifts', function (Blueprint $table) {
            $table->id();  // Первичный ключ
            
            // Связи с другими таблицами
            $table->foreignId('doctor_id')->constrained('doctors')->onDelete('cascade');  // Связь с врачом
            $table->foreignId('cabinet_id')->constrained('cabinets')->onDelete('cascade');  // Связь с кабинетом

            $table->date('date');  // Дата смены (позже будет удалено)

            // Время смены с поддержкой часовых поясов
            $table->timestampTz('start_time');  // Время начала смены
            $table->timestampTz('end_time');    // Время окончания смены

            $table->integer('slot_duration')->default(30);  // Длительность слота для записи (минуты)
            $table->timestamps();  // Временные метки создания и обновления
            $table->softDeletes();  // Мягкое удаление для сохранения истории

            // Индексы для оптимизации запросов
            $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');  // Быстрый поиск по кабинету и дате
            $table->index(['doctor_id', 'date'], 'idx_doctor_date');    // Быстрый поиск по врачу и дате
            
            // Уникальное ограничение для предотвращения дублирования смен
            $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');
        });
    }

    /**
     * Откат миграции - удаление таблицы смен врачей
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_shifts');
    }
};
