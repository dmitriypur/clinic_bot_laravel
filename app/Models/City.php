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

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_city');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function allDoctors()
    {
        return Doctor::whereHas('clinics.cities', function ($q) {
            $q->where('cities.id', $this->id);
        })->distinct();
    }
}
