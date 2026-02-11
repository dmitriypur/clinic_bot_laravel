<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Исправляем AUTO_INCREMENT для MySQL
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Удаляем все записи с неправильными ID (больше 1000000)
            DB::statement('DELETE FROM applications WHERE id > 1000000');

            // Устанавливаем правильное значение AUTO_INCREMENT
            DB::statement('ALTER TABLE applications AUTO_INCREMENT = 1');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откатываем изменения для MySQL
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE applications AUTO_INCREMENT = 202508290745321171');
        }
    }
};
