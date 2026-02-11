<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEndpoint extends Model
{
    public const TYPE_ONEC = 'onec';

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'type',
        'name',
        'base_url',
        'auth_type',
        'credentials',
        'is_active',
        'last_success_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $casts = [
        'credentials' => 'array',
        'is_active' => 'boolean',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $endpoint) {
            if ($endpoint->branch_id && ! $endpoint->clinic_id) {
                $endpoint->clinic_id = $endpoint->branch?->clinic_id
                    ?? Branch::query()->where('id', $endpoint->branch_id)->value('clinic_id');
            }
        });
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
