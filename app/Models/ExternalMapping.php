<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalMapping extends Model
{
    protected $fillable = [
        'clinic_id',
        'local_type',
        'local_id',
        'external_id',
        'meta',
        'last_synced_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
