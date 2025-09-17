<?php

namespace App\Models;

use App\Services\TimezoneService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clinic extends Model
{
    protected $fillable = [
        'name',
        'status',
        'slot_duration',
    ];

    protected $casts = [
        'status' => 'integer',
        'slot_duration' => 'integer',
    ];

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'clinic_city');
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'clinic_doctor');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Получить эффективную длительность слота для клиники
     * Если у клиники не задана длительность, используется значение по умолчанию (30 минут)
     */
    public function getEffectiveSlotDuration(): int
    {
        return $this->slot_duration ?? 30;
    }

    /**
     * Boot the model and register event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Очищаем кэш часового пояса клиники при обновлении
        static::updated(function ($clinic) {
            app(TimezoneService::class)->clearClinicTimezoneCache($clinic->id);
        });

        // Очищаем кэш часового пояса клиники при удалении
        static::deleted(function ($clinic) {
            app(TimezoneService::class)->clearClinicTimezoneCache($clinic->id);
        });

        // Очищаем кэш при изменении связей с городами
        static::saved(function ($clinic) {
            if ($clinic->wasRecentlyCreated || $clinic->wasChanged()) {
                app(TimezoneService::class)->clearClinicTimezoneCache($clinic->id);
            }
        });
    }
}