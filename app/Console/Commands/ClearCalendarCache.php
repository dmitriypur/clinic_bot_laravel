<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearCalendarCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:clear-cache {--user= : Clear cache for specific user} {--all : Clear all calendar cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear calendar cache for better performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = $this->option('user');
        $all = $this->option('all');
        
        if ($all) {
            // Очищаем весь кэш календаря
            $this->clearAllCalendarCache();
        } elseif ($user) {
            // Очищаем кэш для конкретного пользователя
            $this->clearUserCalendarCache($user);
        } else {
            // Очищаем кэш текущего пользователя (если есть)
            if (auth()->check()) {
                $this->clearUserCalendarCache(auth()->id());
            } else {
                $this->error('No user specified and no authenticated user found.');
                return 1;
            }
        }
        
        $this->info('Calendar cache cleared successfully!');
        return 0;
    }
    
    /**
     * Очищает весь кэш календаря
     */
    private function clearAllCalendarCache(): void
    {
        $this->info('Clearing all calendar cache...');
        
        $keys = Cache::get('calendar_cache_keys', []);
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        
        Cache::forget('calendar_cache_keys');
        
        $this->info('All calendar cache cleared.');
    }
    
    /**
     * Очищает кэш для конкретного пользователя
     */
    private function clearUserCalendarCache($userId): void
    {
        $this->info("Clearing calendar cache for user {$userId}...");
        
        // Удаляем все ключи кэша, связанные с пользователем
        $pattern = "calendar_*_{$userId}";
        
        // Получаем все ключи кэша
        $keys = Cache::get('calendar_cache_keys', []);
        
        $userKeys = array_filter($keys, function($key) use ($userId) {
            return str_contains($key, "_{$userId}");
        });
        
        foreach ($userKeys as $key) {
            Cache::forget($key);
        }
        
        $this->info("Calendar cache cleared for user {$userId}.");
    }
}
