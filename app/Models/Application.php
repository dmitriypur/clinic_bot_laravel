<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasCalendarOptimizations;
use Illuminate\Support\Facades\Cache;

class Application extends Model
{
    use HasCalendarOptimizations;
    
    public $incrementing = true;
    protected $keyType = 'integer';

    // Константы статусов приема
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'city_id',
        'clinic_id',
        'branch_id',
        'doctor_id',
        'cabinet_id',
        'appointment_datetime',
        'full_name_parent',
        'full_name',
        'birth_date',
        'phone',
        'promo_code',
        'tg_user_id',
        'tg_chat_id',
        'send_to_1c',
        'appointment_status',
    ];

    protected $casts = [
        'id' => 'integer',
        'city_id' => 'integer',
        'clinic_id' => 'integer',
        'branch_id' => 'integer',
        'doctor_id' => 'integer',
        'cabinet_id' => 'integer',
        'appointment_datetime' => 'datetime',
        'tg_user_id' => 'integer',
        'tg_chat_id' => 'integer',
        'send_to_1c' => 'boolean',
        'appointment_status' => 'string',
    ];

    /**
     * Boot the model and register event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Очищаем кэш календаря при создании заявки
        static::created(function ($application) {
            static::clearCalendarCache();
        });

        // Очищаем кэш календаря при обновлении заявки
        static::updated(function ($application) {
            static::clearCalendarCache();
        });

        // Очищаем кэш календаря при удалении заявки
        static::deleted(function ($application) {
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

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /**
     * Проверяет, запланирован ли прием
     */
    public function isScheduled(): bool
    {
        return $this->appointment_status === self::STATUS_SCHEDULED;
    }

    /**
     * Проверяет, идет ли прием в данный момент
     */
    public function isInProgress(): bool
    {
        return $this->appointment_status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Проверяет, завершен ли прием
     */
    public function isCompleted(): bool
    {
        return $this->appointment_status === self::STATUS_COMPLETED;
    }

    /**
     * Начинает прием
     */
    public function startAppointment(): bool
    {
        if ($this->isScheduled()) {
            $this->appointment_status = self::STATUS_IN_PROGRESS;
            return $this->save();
        }
        return false;
    }

    /**
     * Завершает прием
     */
    public function completeAppointment(): bool
    {
        if ($this->isInProgress()) {
            $this->appointment_status = self::STATUS_COMPLETED;
            return $this->save();
        }
        return false;
    }

    /**
     * Возвращает человекочитаемое название статуса
     */
    public function getStatusLabel(): string
    {
        return match($this->appointment_status) {
            self::STATUS_SCHEDULED => 'Запланирован',
            self::STATUS_IN_PROGRESS => 'В процессе',
            self::STATUS_COMPLETED => 'Завершен',
            default => 'Неизвестно'
        };
    }
}