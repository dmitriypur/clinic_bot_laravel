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
    protected $description = 'Fix applications table id field AUTO_INCREMENT for MySQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            $this->info('This command is only for MySQL databases.');
            return;
        }

        try {
            // Проверяем текущее состояние поля id
            $result = DB::select("SHOW COLUMNS FROM applications LIKE 'id'");
            
            if (empty($result)) {
                $this->error('Field id not found in applications table');
                return;
            }

            $column = $result[0];
            $this->info("Current id field: {$column->Field} {$column->Type} {$column->Extra}");

            // Если поле уже имеет AUTO_INCREMENT, ничего не делаем
            if (str_contains($column->Extra, 'auto_increment')) {
                $this->info('Field id already has AUTO_INCREMENT');
                return;
            }

            // Добавляем AUTO_INCREMENT
            DB::statement('ALTER TABLE applications MODIFY id BIGINT AUTO_INCREMENT');
            
            $this->info('Successfully added AUTO_INCREMENT to applications.id field');
            
            // Проверяем результат
            $result = DB::select("SHOW COLUMNS FROM applications LIKE 'id'");
            $column = $result[0];
            $this->info("Updated id field: {$column->Field} {$column->Type} {$column->Extra}");
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }
}
