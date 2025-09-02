<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Исправляем поле id для MySQL - добавляем AUTO_INCREMENT
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE applications MODIFY id BIGINT AUTO_INCREMENT');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откатываем изменения для MySQL
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE applications MODIFY id BIGINT');
        }
    }
};
