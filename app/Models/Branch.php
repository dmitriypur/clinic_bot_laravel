<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'clinic_id',
        'city_id',
        'name',
        'address',
        'phone',
        'status',
        'slot_duration',
    ];

    protected $casts = [
        'clinic_id' => 'integer',
        'city_id' => 'integer',
        'status' => 'integer',
        'slot_duration' => 'integer',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'branch_doctor');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function cabinets(): HasMany
    {
        return $this->hasMany(Cabinet::class);
    }

    /**
     * Получить эффективную длительность слота для филиала
     * Если у филиала не задана длительность, берется из клиники
     */
    public function getEffectiveSlotDuration(): int
    {
        return $this->slot_duration ?? $this->clinic->slot_duration ?? 30;
    }
}
