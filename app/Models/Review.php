<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'text',
        'rating',
        'user_id',
        'doctor_id',
        'status',
    ];

    protected $casts = [
        'rating' => 'integer',
        'user_id' => 'integer',
        'doctor_id' => 'integer',
        'status' => 'integer',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}