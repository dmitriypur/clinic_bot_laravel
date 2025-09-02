<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Branch;
use App\Models\Application;
use App\Models\DoctorShift;

class Cabinet extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function shifts()
    {
        return $this->hasMany(DoctorShift::class);
    }
}
