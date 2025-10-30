<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmIntegrationLog extends Model
{
    protected $fillable = [
        'clinic_id',
        'application_id',
        'provider',
        'status',
        'payload',
        'response',
        'error_message',
        'attempt',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
