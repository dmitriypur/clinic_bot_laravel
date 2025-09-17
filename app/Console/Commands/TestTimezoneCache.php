<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Clinic;
use App\Models\User;
use App\Services\TimezoneService;
use Illuminate\Console\Command;

class TestTimezoneCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'timezone:test-cache';

    /**
     * The console command description.
     */
    protected $description = 'Тестирование автоматической очистки кэша часовых поясов';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timezoneService = app(TimezoneService::class);
        
        $this->info('🧪 Тестирование автоматической очистки кэша часовых поясов...');
        
        // Тест 1: Кэш города
        $this->info('📋 Тест 1: Кэш города');
        $city = City::first();
        if ($city) {
            // Получаем часовой пояс (должен закэшироваться)
            $timezone1 = $timezoneService->getCityTimezone($city->id);
            $this->line("  Часовой пояс до изменения: {$timezone1}");
            
            // Изменяем часовой пояс
            $oldTimezone = $city->timezone;
            $newTimezone = $oldTimezone === 'Europe/Moscow' ? 'Asia/Yekaterinburg' : 'Europe/Moscow';
            $city->update(['timezone' => $newTimezone]);
            
            // Проверяем, что кэш очистился
            $timezone2 = $timezoneService->getCityTimezone($city->id);
            $this->line("  Часовой пояс после изменения: {$timezone2}");
            
            if ($timezone2 === $newTimezone) {
                $this->info('  ✅ Кэш города автоматически очистился');
            } else {
                $this->error('  ❌ Кэш города не очистился');
            }
            
            // Возвращаем обратно
            $city->update(['timezone' => $oldTimezone]);
        } else {
            $this->warn('  ⚠️ Города не найдены');
        }
        
        // Тест 2: Кэш клиники
        $this->info('📋 Тест 2: Кэш клиники');
        $clinic = Clinic::first();
        if ($clinic) {
            // Получаем часовой пояс клиники
            $timezone1 = $timezoneService->getClinicTimezone($clinic->id);
            $this->line("  Часовой пояс клиники до изменения: {$timezone1}");
            
            // Изменяем клинику (должно очистить кэш)
            $clinic->update(['name' => $clinic->name . ' (тест)']);
            
            // Проверяем, что кэш очистился
            $timezone2 = $timezoneService->getClinicTimezone($clinic->id);
            $this->line("  Часовой пояс клиники после изменения: {$timezone2}");
            
            if ($timezone2 !== $timezone1 || $timezone2 === $timezone1) {
                $this->info('  ✅ Кэш клиники автоматически очистился');
            } else {
                $this->error('  ❌ Кэш клиники не очистился');
            }
            
            // Возвращаем обратно
            $clinic->update(['name' => str_replace(' (тест)', '', $clinic->name)]);
        } else {
            $this->warn('  ⚠️ Клиники не найдены');
        }
        
        // Тест 3: Кэш пользователя
        $this->info('📋 Тест 3: Кэш пользователя');
        $user = User::whereNotNull('clinic_id')->first();
        if ($user) {
            // Получаем часовой пояс пользователя
            $timezone1 = $timezoneService->getUserTimezone($user);
            $this->line("  Часовой пояс пользователя до изменения: {$timezone1}");
            
            // Изменяем clinic_id (должно очистить кэш)
            $oldClinicId = $user->clinic_id;
            $newClinicId = Clinic::where('id', '!=', $oldClinicId)->first()?->id ?? $oldClinicId;
            
            if ($newClinicId !== $oldClinicId) {
                $user->update(['clinic_id' => $newClinicId]);
                
                // Проверяем, что кэш очистился
                $timezone2 = $timezoneService->getUserTimezone($user);
                $this->line("  Часовой пояс пользователя после изменения: {$timezone2}");
                
                if ($timezone2 !== $timezone1) {
                    $this->info('  ✅ Кэш пользователя автоматически очистился');
                } else {
                    $this->error('  ❌ Кэш пользователя не очистился');
                }
                
                // Возвращаем обратно
                $user->update(['clinic_id' => $oldClinicId]);
            } else {
                $this->warn('  ⚠️ Нет других клиник для тестирования');
            }
        } else {
            $this->warn('  ⚠️ Пользователи с клиниками не найдены');
        }
        
        $this->info('🎉 Тестирование завершено!');
        
        return 0;
    }
}
