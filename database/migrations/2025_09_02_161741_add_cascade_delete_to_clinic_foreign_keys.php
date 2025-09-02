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
        // Добавляем каскадное удаление для applications.clinic_id
        if (Schema::hasTable('applications')) {
            try {
                Schema::table('applications', function (Blueprint $table) {
                    $table->dropForeign(['clinic_id']);
                    $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Если внешний ключ не существует, создаем его
                Schema::table('applications', function (Blueprint $table) {
                    $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('cascade');
                });
            }
        }

        // Добавляем каскадное удаление для users.clinic_id
        if (Schema::hasTable('users')) {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropForeign(['clinic_id']);
                    $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Если внешний ключ не существует, создаем его
                Schema::table('users', function (Blueprint $table) {
                    $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откатываем каскадное удаление для applications.clinic_id
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->foreign('clinic_id')->references('id')->on('clinics');
        });

        // Откатываем каскадное удаление для users.clinic_id
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->foreign('clinic_id')->references('id')->on('clinics');
        });
    }
};
