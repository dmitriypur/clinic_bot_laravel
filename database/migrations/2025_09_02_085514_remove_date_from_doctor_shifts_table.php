<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
    private string $table = 'doctor_shifts';

    /**
     * Проверяет наличие индекса в текущей БД.
     */
    private function indexExists(string $indexName): bool
    {
        if (! Schema::hasTable($this->table)) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $result = DB::select(
                'SHOW INDEX FROM `'.$this->table.'` WHERE Key_name = ?',
                [$indexName]
            );

            return ! empty($result);
        }

        if ($driver === 'pgsql') {
            $result = DB::select(
                'SELECT indexname FROM pg_indexes WHERE schemaname = ANY(current_schemas(false)) AND tablename = ? AND indexname = ?',
                [$this->table, $indexName]
            );

            return ! empty($result);
        }

        if ($driver === 'sqlite') {
            $result = DB::select(sprintf("PRAGMA index_list('%s')", $this->table));

            foreach ($result as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Проверяет наличие внешнего ключа в текущей БД.
     */
    private function foreignKeyExists(string $constraintName): bool
    {
        if (! Schema::hasTable($this->table)) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();

            $result = DB::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$database, $this->table, $constraintName]
            );

            return $result !== null;
        }

        return false;
    }

    /**
     * Удаляет внешний ключ, если он существует.
     */
    private function dropForeignIfExists(string $constraintName): void
    {
        if ($this->foreignKeyExists($constraintName)) {
            DB::statement(
                sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $this->table,
                    $constraintName
                )
            );
        }
    }

    /**
     * Удаление поля date и обновление индексов
     */
    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        // Сначала удаляем старые внешние ключи
        $this->dropForeignIfExists('doctor_shifts_cabinet_id_foreign');
        $this->dropForeignIfExists('doctor_shifts_doctor_id_foreign');

        // Затем снимаем индексы/уникальные ограничения, завязанные на поле date
        if ($this->indexExists('idx_cabinet_date')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex('idx_cabinet_date');
            });
        }

        if ($this->indexExists('idx_doctor_date')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex('idx_doctor_date');
            });
        }

        if ($this->indexExists('unique_doctor_shift')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique('unique_doctor_shift');
            });
        }

        // Удаляем поле date, если оно существует
        if (Schema::hasColumn($this->table, 'date')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropColumn('date');
            });
        }

        // Восстанавливаем внешние ключи и добавляем новые индексы
        Schema::table($this->table, function (Blueprint $table) {
            $table->foreign('cabinet_id')
                ->references('id')
                ->on('cabinets')
                ->onDelete('cascade');

            $table->foreign('doctor_id')
                ->references('id')
                ->on('doctors')
                ->onDelete('cascade');

            if (! $this->indexExists('idx_cabinet_start_time')) {
                $table->index(['cabinet_id', 'start_time'], 'idx_cabinet_start_time');
            }

            if (! $this->indexExists('idx_doctor_start_time')) {
                $table->index(['doctor_id', 'start_time'], 'idx_doctor_start_time');
            }

            if (! $this->indexExists('unique_doctor_shift')) {
                $table->unique(['doctor_id', 'cabinet_id', 'start_time', 'end_time'], 'unique_doctor_shift');
            }
        });
    }

    /**
     * Откат миграции - восстановление поля date и старых индексов
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        // Удаляем новые внешние ключи перед удалением индексов
        $this->dropForeignIfExists('doctor_shifts_cabinet_id_foreign');
        $this->dropForeignIfExists('doctor_shifts_doctor_id_foreign');

        // Снимаем новые индексы и уникальное ограничение
        if ($this->indexExists('unique_doctor_shift')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique('unique_doctor_shift');
            });
        }

        // Индексы `idx_cabinet_start_time` и `idx_doctor_start_time` не трогаем,
        // так как они используются внешними ключами и MySQL не позволит их удалить.

        // Восстанавливаем колонку date
        if (! Schema::hasColumn($this->table, 'date')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->date('date')->default('2025-01-01');
            });
        }

        // Возвращаем старые индексы и внешние ключи
        Schema::table($this->table, function (Blueprint $table) {
            if (! $this->indexExists('idx_cabinet_date')) {
                $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');
            }

            if (! $this->indexExists('idx_doctor_date')) {
                $table->index(['doctor_id', 'date'], 'idx_doctor_date');
            }

            if (! $this->indexExists('unique_doctor_shift')) {
                $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');
            }

            $table->foreign('cabinet_id')
                ->references('id')
                ->on('cabinets')
                ->onDelete('cascade');

            $table->foreign('doctor_id')
                ->references('id')
                ->on('doctors')
                ->onDelete('cascade');
        });
    }
};
