<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\AppointmentStatus;

class Appointment extends Model
{
    protected $fillable = [
        'application_id',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'status' => AppointmentStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Связь с заявкой
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Проверяет, идет ли прием в данный момент
     */
    public function isInProgress(): bool
    {
        return $this->status === AppointmentStatus::IN_PROGRESS;
    }

    /**
     * Проверяет, завершен ли прием
     */
    public function isCompleted(): bool
    {
        return $this->status === AppointmentStatus::COMPLETED;
    }

    /**
     * Завершает прием
     */
    public function complete(): bool
    {
        if ($this->isInProgress()) {
            $this->status = AppointmentStatus::COMPLETED;
            $this->completed_at = now();
            return $this->save();
        }
        return false;
    }

    /**
     * Получить данные пациента из связанной заявки
     */
    public function getPatientData(): array
    {
        $application = $this->application;
        if (!$application) {
            return [];
        }

        return [
            'full_name' => $application->full_name,
            'full_name_parent' => $application->full_name_parent,
            'birth_date' => $application->birth_date,
            'phone' => $application->phone,
            'appointment_datetime' => $application->appointment_datetime,
            'clinic' => $application->clinic?->name,
            'doctor' => $application->doctor?->name,
            'city' => $application->city?->name,
            'branch' => $application->branch?->name,
            'cabinet' => $application->cabinet?->name,
        ];
    }

    /**
     * Получить длительность приема в минутах
     */
    public function getDurationInMinutes(): ?int
    {
        if ($this->started_at && $this->completed_at) {
            return (int) $this->started_at->diffInMinutes($this->completed_at);
        }
        return null;
    }

    /**
     * Scope для активных приемов
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', AppointmentStatus::IN_PROGRESS);
    }

    /**
     * Scope для завершенных приемов
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', AppointmentStatus::COMPLETED);
    }
}
