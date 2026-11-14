<?php

namespace App\Services\Booking;

use App\Models\Application;
use App\Models\Cabinet;
use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalBooking
{
    public function createFromWidget(array $data): Application
    {
        if (! isset($data['branch_id'], $data['doctor_id'], $data['appointment_datetime'])) {
            return Application::create($data);
        }

        return DB::transaction(function () use ($data) {
            $cabinetId = $data['cabinet_id'] ?? null;
            $this->lockResources($data['doctor_id'], $cabinetId);
            $application = new Application($data);
            $start = CarbonImmutable::parse($application->getAttributes()['appointment_datetime'], 'UTC');
            $end = $start->addMinutes($application->branch?->getEffectiveSlotDuration() ?? 30);

            if ($this->hasApplicationOverlap($data['doctor_id'], $cabinetId, $start, $end)) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'Это время уже занято. Выберите другое окно.',
                ]);
            }

            $application->save();

            return $application;
        }, 3);
    }

    public function lockResources(int $doctorId, ?int $cabinetId): ?Doctor
    {
        $doctor = Doctor::query()->lockForUpdate()->find($doctorId);
        if ($cabinetId !== null) {
            Cabinet::query()->lockForUpdate()->find($cabinetId);
        }

        return $doctor;
    }

    public function hasApplicationOverlap(int $doctorId, ?int $cabinetId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $applications = Application::query()->where('appointment_datetime', '<', $end->utc())
            ->where(fn (Builder $query) => $query->where('applications.doctor_id', $doctorId)
                ->when($cabinetId, fn (Builder $query, $id) => $query->orWhere('applications.cabinet_id', $id)))
            ->leftJoin('branches', 'branches.id', '=', 'applications.branch_id')
            ->leftJoin('clinics', 'clinics.id', '=', 'branches.clinic_id')
            ->select('applications.appointment_datetime')
            ->selectRaw('coalesce(branches.slot_duration, clinics.slot_duration, 30) as duration')
            ->toBase()->cursor();

        return $applications->contains(fn ($item) => CarbonImmutable::parse($item->appointment_datetime, 'UTC')
            ->addMinutes($item->duration)->greaterThan($start));
    }
}
