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
        // Проверяем, есть ли уже каскадное удаление для applications.clinic_id
        if (Schema::hasTable('applications')) {
            $foreignKeys = Schema::getConnection()->getDoctrineSchemaManager()->listTableForeignKeys('applications');
            $hasCascade = false;
            foreach ($foreignKeys as $foreignKey) {
                if (in_array('clinic_id', $foreignKey->getLocalColumns())) {
                    $hasCascade = true;
                    break;
                }
            }
            
            if (!$hasCascade) {
                Schema::table('applications', function (Blueprint $table) {
                    $table->dropForeign(['clinic_id']);
                    $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('cascade');
                });
            }
        }

        // Проверяем, есть ли уже каскадное удаление для users.clinic_id
        if (Schema::hasTable('users')) {
            $foreignKeys = Schema::getConnection()->getDoctrineSchemaManager()->listTableForeignKeys('users');
            $hasCascade = false;
            foreach ($foreignKeys as $foreignKey) {
                if (in_array('clinic_id', $foreignKey->getLocalColumns())) {
                    $hasCascade = true;
                    break;
                }
            }
            
            if (!$hasCascade) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropForeign(['clinic_id']);
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
