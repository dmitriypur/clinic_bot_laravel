<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixApplicationsIdAutoIncrement extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:applications-id-auto-increment';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Исправляет AUTO_INCREMENT для поля id в таблице applications для MySQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Проверка и исправление AUTO_INCREMENT для таблицы applications...');

        // Проверяем что таблица существует
        if (!Schema::hasTable('applications')) {
            $this->error('Таблица applications не существует!');
            return 1;
        }

        // Проверяем драйвер базы данных
        $driver = DB::connection()->getDriverName();
        
        if ($driver !== 'mysql') {
            $this->warn("Текущий драйвер: {$driver}. Эта команда предназначена для MySQL.");
            $this->info('Для SQLite AUTO_INCREMENT добавляется автоматически.');
            return 0;
        }

        try {
            // Проверяем текущее состояние поля id
            $columnInfo = DB::select("SHOW COLUMNS FROM applications LIKE 'id'");
            
            if (empty($columnInfo)) {
                $this->error('Поле id не найдено в таблице applications!');
                return 1;
            }

            $column = $columnInfo[0];
            $this->info("Текущее состояние поля id: {$column->Field} {$column->Type} {$column->Null} {$column->Key} {$column->Default} {$column->Extra}");

            // Проверяем есть ли уже AUTO_INCREMENT
            if (str_contains($column->Extra, 'auto_increment')) {
                $this->info('AUTO_INCREMENT уже установлен для поля id.');
                return 0;
            }

            // Исправляем поле id - добавляем AUTO_INCREMENT
            $this->info('Добавление AUTO_INCREMENT к полю id...');
            DB::statement('ALTER TABLE applications MODIFY id BIGINT AUTO_INCREMENT');

            // Проверяем результат
            $columnInfoAfter = DB::select("SHOW COLUMNS FROM applications LIKE 'id'");
            $columnAfter = $columnInfoAfter[0];
            
            $this->info("Новое состояние поля id: {$columnAfter->Field} {$columnAfter->Type} {$columnAfter->Null} {$columnAfter->Key} {$columnAfter->Default} {$columnAfter->Extra}");

            if (str_contains($columnAfter->Extra, 'auto_increment')) {
                $this->info('✅ AUTO_INCREMENT успешно добавлен к полю id!');
                $this->info('Теперь можно создавать заявки без указания id.');
                return 0;
            } else {
                $this->error('❌ Не удалось добавить AUTO_INCREMENT к полю id.');
                return 1;
            }

        } catch (\Exception $e) {
            $this->error("Ошибка при исправлении AUTO_INCREMENT: {$e->getMessage()}");
            return 1;
        }
    }
}
