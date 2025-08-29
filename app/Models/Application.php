<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'city_id',
        'clinic_id',
        'branch_id',
        'doctor_id',
        'full_name_parent',
        'full_name',
        'birth_date',
        'phone',
        'promo_code',
        'tg_user_id',
        'tg_chat_id',
        'send_to_1c',
    ];

    protected $casts = [
        'id' => 'integer',
        'city_id' => 'integer',
        'clinic_id' => 'integer',
        'branch_id' => 'integer',
        'doctor_id' => 'integer',
        'tg_user_id' => 'integer',
        'tg_chat_id' => 'integer',
        'send_to_1c' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

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
}