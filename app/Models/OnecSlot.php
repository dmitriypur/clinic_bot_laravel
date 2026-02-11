<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnecSlot extends Model
{
    public const STATUS_FREE = 'free';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'clinic_id',
        'doctor_id',
        'branch_id',
        'cabinet_id',
        'start_at',
        'end_at',
        'status',
        'external_slot_id',
        'booking_uuid',
        'payload_hash',
        'source_payload',
        'synced_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'synced_at' => 'datetime',
        'source_payload' => 'array',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function isFree(): bool
    {
        return $this->status === self::STATUS_FREE;
    }

    public function isBookedLocally(): bool
    {
        return $this->status === self::STATUS_BOOKED && ! empty($this->booking_uuid);
    }

    public function isBookedExternally(): bool
    {
        return $this->status === self::STATUS_BOOKED && empty($this->booking_uuid);
    }
}
