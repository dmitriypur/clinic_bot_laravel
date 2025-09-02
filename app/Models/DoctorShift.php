<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Cabinet;
use App\Models\Doctor;

class DoctorShift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cabinet_id',
        'doctor_id',
        'start_time',
        'end_time',
        'slot_duration',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'slot_duration' => 'integer',
    ];

    // Отношения
    public function cabinet() { return $this->belongsTo(Cabinet::class); }
    public function doctor()  { return $this->belongsTo(Doctor::class, 'doctor_id'); }

    // Scope: для диапазона дат
    public function scopeBetween($q, $from, $to) {
        return $q->where(function($qq) use ($from, $to) {
            $qq->whereBetween('start_time', [$from, $to])
               ->orWhereBetween('end_time', [$from, $to])
               ->orWhere(function($q2) use ($from, $to) {
                   $q2->where('start_time', '<=', $from)->where('end_time', '>=', $to);
               });
        });
    }
}
