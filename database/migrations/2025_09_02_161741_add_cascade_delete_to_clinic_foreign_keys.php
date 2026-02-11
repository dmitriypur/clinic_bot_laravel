<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Удаляет внешний ключ, если он существует.
     */
    private function dropForeignIfExists(string $table, string $constraint): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $exists = DB::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$database, $table, $constraint]
            );

            if ($exists !== null) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraint));
            }

            return;
        }

        // Для остальных драйверов пробуем удалить стандартным способом
        Schema::table($table, function (Blueprint $blueprint) use ($constraint) {
            try {
                $blueprint->dropForeign($constraint);
            } catch (\Throwable $e) {
                // Ограничение может отсутствовать
            }
        });
    }

    /**
     * Применяет требуемое поведение on delete для указанного столбца.
     */
    private function applyForeign(string $table, string $column, string $referencedTable, ?string $onDelete = null): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $onDelete) {
            $foreign = $blueprint->foreign($column)->references('id')->on($referencedTable);

            if ($onDelete !== null) {
                $foreign->onDelete($onDelete);
            }
        });
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->dropForeignIfExists('applications', 'applications_clinic_id_foreign');
        $this->applyForeign('applications', 'clinic_id', 'clinics', 'cascade');

        $this->dropForeignIfExists('users', 'users_clinic_id_foreign');
        $this->applyForeign('users', 'clinic_id', 'clinics', 'cascade');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropForeignIfExists('applications', 'applications_clinic_id_foreign');
        $this->applyForeign('applications', 'clinic_id', 'clinics');

        $this->dropForeignIfExists('users', 'users_clinic_id_foreign');
        $this->applyForeign('users', 'clinic_id', 'clinics');
    }
};
