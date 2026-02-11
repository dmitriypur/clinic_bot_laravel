<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

trait HasCalendarOptimizations
{
    /**
     * Оптимизированный запрос смен с предзагрузкой связей
     */
    public function scopeWithCalendarRelations(Builder $query): Builder
    {
        return $query->with([
            'doctor:id,full_name,specialization',
            'cabinet:id,name,branch_id',
            'cabinet.branch:id,name,clinic_id,city_id',
            'cabinet.branch.clinic:id,name',
            'cabinet.branch.city:id,name',
        ]);
    }

    /**
     * Оптимизированный запрос заявок с предзагрузкой связей
     */
    public function scopeWithApplicationRelations(Builder $query): Builder
    {
        return $query->with([
            'doctor:id,full_name,specialization',
            'cabinet:id,name,branch_id',
            'branch:id,name,clinic_id,city_id',
            'clinic:id,name',
            'city:id,name',
        ]);
    }

    /**
     * Кэширование запросов для календаря
     */
    public function scopeCachedCalendarData(Builder $query, string $cacheKey, int $ttl = 300)
    {
        $cacheKey = "calendar_{$cacheKey}_".(auth()->id() ?? 'guest');

        // Сохраняем ключ кэша для последующей очистки
        $existingKeys = \Illuminate\Support\Facades\Cache::get('calendar_cache_keys', []);
        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            \Illuminate\Support\Facades\Cache::put('calendar_cache_keys', $existingKeys, 3600); // 1 час
        }

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Оптимизация запросов по датам с индексами
     */
    public function scopeOptimizedDateRange(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('start_time', [$start, $end])
            ->orderBy('start_time');
    }

    /**
     * Пакетная загрузка данных для календаря
     */
    public function scopeBatchCalendarData(Builder $query, array $dateRanges): Builder
    {
        $conditions = [];

        foreach ($dateRanges as $range) {
            $conditions[] = [
                'start_time' => '>=',
                'end_time' => '<=',
                'values' => [$range['start'], $range['end']],
            ];
        }

        return $query->where(function ($q) use ($conditions) {
            foreach ($conditions as $condition) {
                $q->orWhereBetween('start_time', $condition['values']);
            }
        });
    }

    /**
     * Оптимизация для больших объемов данных
     */
    public function scopeChunkedCalendarData(Builder $query, int $chunkSize = 1000): Builder
    {
        return $query->chunk($chunkSize, function ($records) {
            // Обработка записей порциями
            foreach ($records as $record) {
                yield $record;
            }
        });
    }

    /**
     * Индексы для оптимизации календаря
     */
    public static function getCalendarIndexes(): array
    {
        return [
            'idx_start_time' => ['start_time'],
            'idx_cabinet_datetime' => ['cabinet_id', 'appointment_datetime'],
            'idx_doctor_datetime' => ['doctor_id', 'appointment_datetime'],
            'idx_clinic_datetime' => ['clinic_id', 'appointment_datetime'],
        ];
    }

    /**
     * Статистика производительности запросов
     */
    public function getQueryStats(Builder $query): array
    {
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        $results = $query->get();

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage();

        return [
            'execution_time' => round(($endTime - $startTime) * 1000, 2), // ms
            'memory_usage' => round(($memoryAfter - $memoryBefore) / 1024 / 1024, 2), // MB
            'records_count' => $results->count(),
            'query_sql' => $query->toSql(),
            'query_bindings' => $query->getBindings(),
        ];
    }
}
