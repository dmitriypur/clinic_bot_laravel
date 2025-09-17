<?php

namespace App\Console\Commands;

use App\Services\TimezoneService;
use Illuminate\Console\Command;

class ClearTimezoneCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'timezone:clear-cache {--all : Очистить весь кэш часовых поясов}';

    /**
     * The console command description.
     */
    protected $description = 'Очистка кэша часовых поясов';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timezoneService = app(TimezoneService::class);

        if ($this->option('all')) {
            $this->info('Очистка всего кэша часовых поясов...');
            $timezoneService->clearAllTimezoneCache();
            $this->info('✅ Весь кэш часовых поясов очищен');
        } else {
            $this->info('Очистка кэша часовых поясов...');
            
            // Очищаем кэш городов
            $cities = \App\Models\City::pluck('id');
            foreach ($cities as $cityId) {
                $timezoneService->clearCityTimezoneCache($cityId);
            }
            
            // Очищаем кэш клиник
            $clinics = \App\Models\Clinic::pluck('id');
            foreach ($clinics as $clinicId) {
                $timezoneService->clearClinicTimezoneCache($clinicId);
            }
            
            $this->info('✅ Кэш часовых поясов очищен');
        }

        return 0;
    }
}
