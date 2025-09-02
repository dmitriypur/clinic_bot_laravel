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
    ];

    protected $casts = [
        'clinic_id' => 'integer',
        'city_id' => 'integer',
        'status' => 'integer',
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
}
