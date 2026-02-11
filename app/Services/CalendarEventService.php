<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Slots\SlotData;
use App\Services\Slots\SlotProviderFactory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarEventService
{
    public function __construct(
        private readonly SlotProviderFactory $slotProviderFactory,
    ) {}

    public function generateEvents(array $fetchInfo, array $filters, User $user): array
    {
        $appTimezone = config('app.timezone', 'UTC');

        $rangeStart = Carbon::parse($fetchInfo['start'], $appTimezone)->setTimezone('UTC');
        $rangeEnd = Carbon::parse($fetchInfo['end'], $appTimezone)->setTimezone('UTC');

        $clinics = $this->resolveClinics($filters, $user);

        $events = [];

        foreach ($clinics as $clinic) {
            $provider = $this->slotProviderFactory->make($clinic);

            $slots = $provider->getSlots($rangeStart, $rangeEnd, $filters, $user);

            foreach ($slots as $slot) {
                [$isOccupied, $application] = $this->resolveSlotState($slot, $user);

                $events[] = $this->createEventData($slot, $isOccupied, $application);
            }
        }

        return $events;
    }

    private function resolveSlotState(SlotData $slot, User $user): array
    {
        $occupied = $slot->externallyOccupied;
        $application = $this->findSlotApplication($slot, $user);

        if ($application) {
            $occupied = true;
        }

        return [$occupied, $application];
    }

    private function findSlotApplication(SlotData $slot, User $user): ?Application
    {
        $slotStartUtc = $slot->start->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

        $query = Application::query()
            ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
            ->where('clinic_id', $slot->clinicId)
            ->where('appointment_datetime', $slotStartUtc);

        if ($slot->cabinetId) {
            $query->where('cabinet_id', $slot->cabinetId);
        }

        if ($slot->doctorId) {
            $query->where('doctor_id', $slot->doctorId);
        }

        if ($user->isPartner()) {
            $query->where('clinic_id', $user->clinic_id);
        }

        if ($user->isDoctor()) {
            $query->where('doctor_id', $user->doctor_id);
        }

        return $query->first();
    }

    private function createEventData(SlotData $slot, bool $isOccupied, ?Application $application): array
    {
        $config = config('calendar');
        $appTimezone = config('app.timezone', 'UTC');

        $slotStartApp = $slot->start->copy()->setTimezone($appTimezone);
        $slotEndApp = $slot->end->copy()->setTimezone($appTimezone);

        $isPast = $slotStartApp->isPast();

        if ($isPast) {
            $backgroundColor = $isOccupied ? '#6B7280' : '#9CA3AF';
        } else {
            if ($isOccupied && $application) {
                $backgroundColor = match ($application->appointment_status) {
                    Application::STATUS_IN_PROGRESS => '#3B82F6',
                    Application::STATUS_COMPLETED => '#4a7b6c',
                    default => $config['colors']['occupied_slot'],
                };
            } elseif ($isOccupied && ! $application) {
                $backgroundColor = $config['colors']['occupied_slot'];
            } else {
                $backgroundColor = $config['colors']['free_slot'];
            }
        }

        $title = $isOccupied ? ($application?->full_name ?? 'Занят') : 'Свободен';

        $extendedProps = [
            'slot_id' => $slot->id,
            'slot_start' => $slotStartApp->toIso8601String(),
            'slot_end' => $slotEndApp->toIso8601String(),
            'slot_start_utc' => $slot->start->toIso8601String(),
            'slot_end_utc' => $slot->end->toIso8601String(),
            'is_past' => $isPast,
            'is_occupied' => $isOccupied,
            'clinic_id' => $slot->clinicId,
            'clinic_name' => $slot->meta['clinic_name'] ?? null,
            'branch_id' => $slot->branchId ?? ($slot->meta['branch_id'] ?? null),
            'branch_name' => $slot->meta['branch_name'] ?? null,
            'cabinet_id' => $slot->cabinetId ?? ($slot->meta['cabinet_id'] ?? null),
            'cabinet_name' => $slot->meta['cabinet_name'] ?? null,
            'doctor_id' => $slot->doctorId,
            'doctor_name' => $slot->meta['doctor_name'] ?? null,
            'speciality' => $slot->meta['speciality'] ?? null,
            'city_id' => $slot->meta['city_id'] ?? null,
            'city_name' => $slot->meta['city_name'] ?? null,
            'source' => $slot->source,
            'externally_occupied' => $slot->externallyOccupied,
            'booking_uuid' => $slot->meta['booking_uuid'] ?? null,
            'is_local_booking' => $slot->meta['is_local_booking'] ?? false,
            'shift_id' => $slot->meta['shift_id'] ?? null,
            'onec_slot_id' => $slot->meta['onec_slot_id'] ?? null,
            'raw' => $slot->meta['raw_payload'] ?? null,
        ];

        if ($application) {
            $extendedProps['application_id'] = $application->id;
            $extendedProps['patient_name'] = $application->full_name;
            $extendedProps['appointment_status'] = $application->appointment_status;
            $extendedProps['clinic_name'] ??= $application->clinic?->name;
            $extendedProps['branch_name'] ??= $application->branch?->name;
            $extendedProps['cabinet_name'] ??= $application->cabinet?->name;
            $extendedProps['doctor_name'] ??= $application->doctor?->full_name;
        }

        return [
            'id' => $slot->id,
            'title' => $title,
            'start' => $slotStartApp->toIso8601String(),
            'end' => $slotEndApp->toIso8601String(),
            'backgroundColor' => $backgroundColor,
            'extendedProps' => $extendedProps,
        ];
    }

    private function resolveClinics(array $filters, User $user): Collection
    {
        $query = Clinic::query()->with('branches.integrationEndpoint');

        if (! empty($filters['clinic_ids'])) {
            $query->whereIn('id', (array) $filters['clinic_ids']);
        } elseif ($user->isPartner() && $user->clinic_id) {
            $query->where('id', $user->clinic_id);
        } elseif ($user->isDoctor() && $user->doctor_id) {
            $query->whereHas('branches.doctors', function ($q) use ($user) {
                $q->where('doctors.id', $user->doctor_id);
            });
        }

        return $query->get();
    }
}
