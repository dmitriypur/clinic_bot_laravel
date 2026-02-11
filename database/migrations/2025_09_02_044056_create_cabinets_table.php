<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Миграция для создания таблицы кабинетов
 *
 * Создает таблицу cabinets для хранения информации о кабинетах в филиалах клиник.
 * Каждый кабинет привязан к филиалу и имеет название и статус активности.
 */
return new class extends Migration
{
    /**
     * Создание таблицы кабинетов
     */
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();  // Первичный ключ
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');  // Связь с филиалом, каскадное удаление
            $table->string('name', 500);  // Название кабинета (до 500 символов)
            $table->integer('status');  // Статус: 1 - активный, 0 - неактивный
            $table->timestamps();  // Временные метки создания и обновления

            // Индексы для оптимизации запросов
            $table->index('branch_id');  // Быстрый поиск по филиалу
            $table->index('status');     // Быстрый поиск по статусу
            $table->index('name');       // Быстрый поиск по названию
        });
    }

    /**
     * Откат миграции - удаление таблицы кабинетов
     */
    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};
