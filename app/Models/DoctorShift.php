<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Cabinet;
use App\Models\Doctor;

/**
 * Модель смены врача
 * 
 * Смена врача - это период времени, когда конкретный врач работает в определенном кабинете.
 * Включает время начала и окончания смены, а также длительность слота для записи пациентов.
 * Поддерживает мягкое удаление (soft delete) для сохранения истории.
 */
class DoctorShift extends Model
{
    use SoftDeletes;

    /**
     * Поля, которые можно массово заполнять
     */
    protected $fillable = [
        'cabinet_id',      // ID кабинета, где работает врач
        'doctor_id',       // ID врача, который работает в смене
        'start_time',      // Время начала смены
        'end_time',        // Время окончания смены
        'slot_duration',   // Длительность слота для записи пациентов (в минутах)
    ];

    /**
     * Приведение типов атрибутов
     */
    protected $casts = [
        'start_time' => 'datetime',     // Время начала как объект Carbon
        'end_time'   => 'datetime',     // Время окончания как объект Carbon
        'slot_duration' => 'integer',   // Длительность слота как целое число
    ];

    /**
     * Связь с кабинетом
     * Каждая смена принадлежит одному кабинету
     */
    public function cabinet() { 
        return $this->belongsTo(Cabinet::class); 
    }
    
    /**
     * Связь с врачом
     * Каждая смена принадлежит одному врачу
     */
    public function doctor() { 
        return $this->belongsTo(Doctor::class, 'doctor_id'); 
    }

    /**
     * Scope для поиска смен в диапазоне дат
     * Находит смены, которые пересекаются с указанным временным диапазоном
     */
    public function scopeBetween($q, $from, $to) {
        return $q->where(function($qq) use ($from, $to) {
            $qq->whereBetween('start_time', [$from, $to])  // Смена начинается в диапазоне
               ->orWhereBetween('end_time', [$from, $to])  // Смена заканчивается в диапазоне
               ->orWhere(function($q2) use ($from, $to) {  // Смена полностью покрывает диапазон
                   $q2->where('start_time', '<=', $from)->where('end_time', '>=', $to);
               });
        });
    }
}
