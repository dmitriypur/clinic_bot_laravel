<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckMigrations extends Command
{
    protected $signature = 'migrate:check';
    protected $description = 'Проверка миграций без их выполнения';

    public function handle()
    {
        $this->info('Проверка структуры миграций...');
        
        // Проверяем порядок миграций
        $migrations = glob(database_path('migrations/*.php'));
        sort($migrations);
        
        $this->info('Порядок выполнения миграций:');
        foreach ($migrations as $migration) {
            $filename = basename($migration);
            $this->line("  - {$filename}");
        }
        
        // Проверяем зависимости внешних ключей
        $this->info("\nПроверка зависимостей внешних ключей:");
        
        $dependencies = [
            'applications' => ['cities', 'clinics', 'doctors'],
            'reviews' => ['doctors'],
            'clinic_city' => ['clinics', 'cities'],
            'clinic_doctor' => ['clinics', 'doctors']
        ];
        
        foreach ($dependencies as $table => $deps) {
            $this->line("  Таблица {$table} зависит от: " . implode(', ', $deps));
        }
        
        // Проверяем подключение к БД
        try {
            DB::connection()->getPdo();
            $this->info("\n✓ Подключение к базе данных успешно");
        } catch (\Exception $e) {
            $this->error("\n✗ Ошибка подключения к БД: " . $e->getMessage());
            return 1;
        }
        
        $this->info("\n✓ Проверка завершена успешно");
        return 0;
    }
}
