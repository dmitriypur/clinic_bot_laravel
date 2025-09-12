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
        Schema::create('application_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique(); // Новая, Записан, Отменен
            $table->string('slug', 50)->unique(); // new, scheduled, cancelled
            $table->string('color', 20)->default('gray'); // Цвет для отображения в админке
            $table->integer('sort_order')->default(0); // Порядок сортировки
            $table->boolean('is_active')->default(true); // Активен ли статус
            $table->timestamps();
            
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_statuses');
    }
};