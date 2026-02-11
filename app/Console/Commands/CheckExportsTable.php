<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckExportsTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exports:check-table {--create : Создать таблицу если не существует}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверяет существование таблицы exports и создает её при необходимости';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Проверка таблицы exports...');

        if (Schema::hasTable('exports')) {
            $this->info('✅ Таблица exports существует');

            // Проверяем структуру таблицы
            $columns = Schema::getColumnListing('exports');
            $requiredColumns = ['id', 'completed_at', 'file_disk', 'file_name', 'exporter', 'processed_rows', 'total_rows', 'successful_rows', 'user_id', 'created_at', 'updated_at'];

            $missingColumns = array_diff($requiredColumns, $columns);
            if (empty($missingColumns)) {
                $this->info('✅ Структура таблицы корректна');
            } else {
                $this->error('❌ Отсутствуют колонки: '.implode(', ', $missingColumns));

                return 1;
            }

            // Показываем статистику
            $count = DB::table('exports')->count();
            $this->info("📊 Количество экспортов: {$count}");

        } else {
            $this->warn('❌ Таблица exports не существует');

            if ($this->option('create')) {
                $this->info('🔧 Создание таблицы exports...');
                $this->createExportsTable();
            } else {
                $this->error('💡 Используйте --create для автоматического создания таблицы');
                $this->info('Или выполните: php artisan migrate');

                return 1;
            }
        }

        return 0;
    }

    private function createExportsTable()
    {
        try {
            Schema::create('exports', function ($table) {
                $table->id();
                $table->timestamp('completed_at')->nullable();
                $table->string('file_disk');
                $table->string('file_name')->nullable();
                $table->string('exporter');
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('total_rows');
                $table->unsignedInteger('successful_rows')->default(0);
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });

            $this->info('✅ Таблица exports создана успешно');

        } catch (\Exception $e) {
            $this->error('❌ Ошибка при создании таблицы: '.$e->getMessage());

            return 1;
        }
    }
}
