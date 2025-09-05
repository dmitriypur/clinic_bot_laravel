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
        'timezone',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

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
