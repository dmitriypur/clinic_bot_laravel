<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Branch;
use App\Models\Application;
use App\Models\DoctorShift;

/**
 * Модель кабинета
 * 
 * Кабинет - это физическое помещение в филиале клиники, где принимает врач.
 * Каждый кабинет принадлежит одному филиалу и может иметь множество смен врачей.
 * Также к кабинету привязываются заявки на прием.
 */
class Cabinet extends Model
{
    /**
     * Поля, которые можно массово заполнять
     */
    protected $fillable = [
        'branch_id',    // ID филиала, к которому принадлежит кабинет
        'name',         // Название кабинета (например, "Кабинет 101", "Процедурный кабинет")
        'status',       // Статус кабинета: 1 - активный, 0 - неактивный
    ];

    /**
     * Приведение типов атрибутов
     */
    protected $casts = [
        'status' => 'integer',  // Статус приводится к целому числу
    ];

    /**
     * Связь с филиалом
     * Каждый кабинет принадлежит одному филиалу
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Связь с заявками на прием
     * К кабинету может быть привязано множество заявок
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Связь со сменами врачей
     * В кабинете может работать множество смен разных врачей
     */
    public function shifts()
    {
        return $this->hasMany(DoctorShift::class);
    }
}
