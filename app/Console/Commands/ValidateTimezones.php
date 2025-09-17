<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\TimezoneService;
use Illuminate\Console\Command;

class ValidateTimezones extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'timezone:validate {--fix : Автоматически исправить некорректные часовые пояса}';

    /**
     * The console command description.
     */
    protected $description = 'Валидация часовых поясов в базе данных';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Проверка часовых поясов в базе данных...');
        
        $timezoneService = app(TimezoneService::class);
        $cities = City::all();
        $errors = [];
        $fixed = 0;

        foreach ($cities as $city) {
            $issues = $this->validateCityTimezone($city, $timezoneService);
            
            if (!empty($issues)) {
                $errors[] = [
                    'city' => $city,
                    'issues' => $issues
                ];
                
                if ($this->option('fix')) {
                    $this->fixCityTimezone($city, $timezoneService);
                    $fixed++;
                }
            }
        }

        if (empty($errors)) {
            $this->info('✅ Все часовые пояса корректны!');
            return 0;
        }

        $this->error('❌ Найдены проблемы с часовыми поясами:');
        
        foreach ($errors as $error) {
            $this->line("Город: {$error['city']->name} (ID: {$error['city']->id})");
            foreach ($error['issues'] as $issue) {
                $this->line("  - {$issue}");
            }
        }

        if ($this->option('fix')) {
            $this->info("🔧 Исправлено {$fixed} городов");
        } else {
            $this->info('💡 Используйте --fix для автоматического исправления');
        }

        return 1;
    }

    /**
     * Валидация часового пояса города
     */
    private function validateCityTimezone(City $city, TimezoneService $timezoneService): array
    {
        $issues = [];

        // Проверяем, что часовой пояс не пустой
        if (empty($city->timezone)) {
            $issues[] = 'Часовой пояс не указан';
            return $issues;
        }

        // Проверяем, что часовой пояс существует
        if (!in_array($city->timezone, timezone_identifiers_list())) {
            $issues[] = "Часовой пояс '{$city->timezone}' не существует";
        }

        // Проверяем, что это российский часовой пояс
        $russianTimezones = array_keys($timezoneService->getRussianTimezones());
        if (!in_array($city->timezone, $russianTimezones)) {
            $issues[] = "Часовой пояс '{$city->timezone}' не является российским";
        }

        return $issues;
    }

    /**
     * Автоматическое исправление часового пояса города
     */
    private function fixCityTimezone(City $city, TimezoneService $timezoneService): void
    {
        $suggestedTimezone = $timezoneService->getTimezoneByCityName($city->name);
        
        if ($suggestedTimezone !== $city->timezone) {
            $city->update(['timezone' => $suggestedTimezone]);
            $this->line("  🔧 Исправлен часовой пояс: {$city->timezone} -> {$suggestedTimezone}");
        }
    }
}
