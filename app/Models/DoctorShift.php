<?php

namespace App\Models;

use App\Traits\HasCalendarOptimizations;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * Модель смены врача
 *
 * Смена врача - это период времени, когда конкретный врач работает в определенном кабинете.
 * Включает время начала и окончания смены, а также длительность слота для записи пациентов.
 * Поддерживает мягкое удаление (soft delete) для сохранения истории.
 */
class DoctorShift extends Model
{
    use HasCalendarOptimizations, SoftDeletes;

    /**
     * Поля, которые можно массово заполнять
     */
    protected $fillable = [
        'cabinet_id',      // ID кабинета, где работает врач
        'doctor_id',       // ID врача, который работает в смене
        'start_time',      // Время начала смены
        'end_time',        // Время окончания смены
    ];

    /**
     * Приведение типов атрибутов
     */
    protected $casts = [
        'start_time' => 'datetime',     // Время начала как объект Carbon
        'end_time' => 'datetime',     // Время окончания как объект Carbon
    ];

    /**
     * Boot the model and register event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Очищаем кэш календаря при создании смены
        static::created(function ($shift) {
            static::clearCalendarCache();
        });

        // Очищаем кэш календаря при обновлении смены
        static::updated(function ($shift) {
            static::clearCalendarCache();
        });

        // Очищаем кэш календаря при удалении смены
        static::deleted(function ($shift) {
            static::clearCalendarCache();
        });

        // Очищаем кэш календаря при восстановлении смены
        static::restored(function ($shift) {
            static::clearCalendarCache();
        });
    }

    /**
     * Очищает кэш календаря
     */
    protected static function clearCalendarCache(): void
    {
        // Очищаем все ключи кэша календаря
        $keys = Cache::get('calendar_cache_keys', []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        // Очищаем ключ со списком ключей
        Cache::forget('calendar_cache_keys');
    }

    /**
     * Связь с кабинетом
     * Каждая смена принадлежит одному кабинету
     */
    public function cabinet()
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * Связь с врачом
     * Каждая смена принадлежит одному врачу
     */
    public function doctor()
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    /**
     * Scope для поиска смен в диапазоне дат
     * Находит смены, которые пересекаются с указанным временным диапазоном
     */
    public function scopeBetween($q, $from, $to)
    {
        return $q->where(function ($qq) use ($from, $to) {
            $qq->whereBetween('start_time', [$from, $to])  // Смена начинается в диапазоне
                ->orWhereBetween('end_time', [$from, $to])  // Смена заканчивается в диапазоне
                ->orWhere(function ($q2) use ($from, $to) {  // Смена полностью покрывает диапазон
                    $q2->where('start_time', '<=', $from)->where('end_time', '>=', $to);
                });
        });
    }

    /**
     * Получить эффективную длительность слота для смены
     * Получаем из филиала через кабинет
     */
    public function getEffectiveSlotDuration(): int
    {
        // Получаем из филиала через кабинет
        $branch = $this->cabinet->branch ?? null;
        if ($branch) {
            return $branch->getEffectiveSlotDuration();
        }

        // Если филиал не найден, возвращаем значение по умолчанию
        return 30;
    }

    /**
     * Получить все доступные слоты времени для смены
     * Разбивает время смены на слоты заданной длительности
     */
    public function getTimeSlots(): array
    {
        $slots = [];
        $slotDuration = $this->getEffectiveSlotDuration();
        $appTimezone = config('app.timezone', 'UTC');

        $current = Carbon::parse($this->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);
        $end = Carbon::parse($this->getRawOriginal('end_time'), 'UTC')->setTimezone($appTimezone);

        while ($current->lt($end)) {
            $slotStart = $current->copy();
            $slotEnd = $current->copy()->addMinutes($slotDuration);

            // Проверяем, что слот не выходит за пределы смены
            if ($slotEnd->gt($end)) {
                $slotEnd = $end->copy();
            }

            $slots[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'duration' => $slotDuration,
                'formatted' => $slotStart->format('H:i').' - '.$slotEnd->format('H:i'),
            ];

            $current->addMinutes($slotDuration);
        }

        return $slots;
    }
}
