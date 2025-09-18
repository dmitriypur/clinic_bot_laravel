<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * Правила валидации для модели
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:cities,name',
            'status' => 'required|integer|in:0,1',
        ];
    }

    /**
     * Правила валидации для обновления
     */
    public static function updateValidationRules(int $id): array
    {
        return [
            'name' => 'required|string|max:255|unique:cities,name,' . $id,
            'status' => 'required|integer|in:0,1',
        ];
    }

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_city');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function allDoctors()
    {
        return Doctor::whereHas('clinics.cities', function ($q) {
            $q->where('cities.id', $this->id);
        })->distinct();
    }

    public function allDoctorsByBranches()
    {
        return Doctor::whereHas('branches', function ($q) {
            $q->where('city_id', $this->id);
        })->distinct();
    }

}
