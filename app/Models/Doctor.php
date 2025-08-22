<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Doctor extends Model
{
    protected $fillable = [
        'last_name',
        'first_name',
        'second_name',
        'experience',
        'age',
        'photo_src',
        'diploma_src',
        'status',
        'age_admission_from',
        'age_admission_to',
        'sum_ratings',
        'count_ratings',
        'uuid',
        'review_link',
    ];

    protected $casts = [
        'experience' => 'integer',
        'age' => 'integer',
        'status' => 'integer',
        'age_admission_from' => 'integer',
        'age_admission_to' => 'integer',
        'sum_ratings' => 'integer',
        'count_ratings' => 'integer',
        'uuid' => 'string',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_doctor');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function getRatingAttribute(): float
    {
        if ($this->count_ratings == 0) {
            return 0;
        }
        
        return round($this->sum_ratings / $this->count_ratings, 1);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name . ' ' . $this->second_name);
    }
}