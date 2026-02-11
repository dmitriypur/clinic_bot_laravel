<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_endpoints')) {
            return;
        }

        $this->dropForeignIfExists('integration_endpoints', 'integration_endpoints_clinic_id_foreign');
        $this->dropUniqueIfExists('integration_endpoints', 'integration_endpoints_clinic_id_unique');

        Schema::table('integration_endpoints', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('clinic_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_active')
                ->default(true)
                ->change();

            $table->index('branch_id');
        });

        $this->addForeign('integration_endpoints', 'clinic_id', 'clinics', 'cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('integration_endpoints')) {
            return;
        }

        $this->dropForeignIfExists('integration_endpoints', 'integration_endpoints_branch_id_foreign');

        Schema::table('integration_endpoints', function (Blueprint $table) {
            $table->dropIndex('integration_endpoints_branch_id_index');
            $table->dropColumn('branch_id');
        });

        $this->addUnique('integration_endpoints', 'clinic_id');
        $this->addForeign('integration_endpoints', 'clinic_id', 'clinics', 'cascade');
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
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

        Schema::table($table, function (Blueprint $blueprint) use ($constraint) {
            try {
                $blueprint->dropForeign($constraint);
            } catch (\Throwable $e) {
                // игнорируем отсутствие ограничения
            }
        });
    }

    private function dropUniqueIfExists(string $table, string $index): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $exists = DB::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "UNIQUE"',
                [$database, $table, $index]
            );

            if ($exists !== null) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
            }

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            try {
                $blueprint->dropUnique($index);
            } catch (\Throwable $e) {
                // игнорируем отсутствие индекса
            }
        });
    }

    private function addForeign(string $table, string $column, string $referencedTable, ?string $onDelete = null): void
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

    private function addUnique(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->unique($column);
        });
    }
};
