<?php

namespace App\Services\Slots;

use App\Models\Clinic;
use App\Models\OnecSlot;
use App\Models\User;
use App\Services\CalendarFilterService;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class OneCSlotProvider implements SlotProviderInterface
{
    public function __construct(
        private readonly Clinic $clinic,
        private readonly CalendarFilterService $filterService,
    ) {}

    public function getSlots(CarbonInterface $from, CarbonInterface $to, array $filters, User $user): Collection
    {
        $query = OnecSlot::query()
            ->with(['branch.city', 'cabinet', 'doctor'])
            ->where('clinic_id', $this->clinic->id)
            ->whereBetween('start_at', [$from, $to]);

        if (! empty($filters['branch_ids'])) {
            $query->whereIn('branch_id', (array) $filters['branch_ids']);
        }

        if (! empty($filters['doctor_ids'])) {
            $query->whereIn('doctor_id', (array) $filters['doctor_ids']);
        }

        if ($user->isDoctor() && $user->doctor_id) {
            $query->where('doctor_id', $user->doctor_id);
        }

        $slots = $this->deduplicateOverlappingSlots($query->get());

        return $slots->map(function (OnecSlot $slot) {
            $payload = $slot->source_payload ?? [];
            $branch = $slot->branch;
            $cabinet = $slot->cabinet;
            $doctor = $slot->doctor;

            return new SlotData(
                id: 'onec:'.$slot->id,
                start: $slot->start_at->copy(),
                end: $slot->end_at->copy(),
                clinicId: $slot->clinic_id,
                branchId: $slot->branch_id,
                cabinetId: $slot->cabinet_id,
                doctorId: $slot->doctor_id,
                source: 'onec',
                externallyOccupied: $slot->status === OnecSlot::STATUS_BOOKED && $slot->isBookedExternally(),
                meta: [
                    'doctor_name' => $doctor?->full_name ?? Arr::get($payload, 'doctor.efio') ?? Arr::get($payload, 'doctor_name'),
                    'speciality' => Arr::get($payload, 'doctor.espec') ?? Arr::get($payload, 'doctor_speciality') ?? $doctor?->speciality ?? null,
                    'clinic_name' => Arr::get($payload, 'clinic') ?? $this->clinic->name,
                    'branch_name' => $branch?->name,
                    'cabinet_name' => $cabinet?->name,
                    'city_id' => $branch?->city_id,
                    'city_name' => $branch?->city?->name,
                    'branch_id' => $slot->branch_id,
                    'cabinet_id' => $slot->cabinet_id,
                    'onec_slot_id' => $slot->external_slot_id,
                    'booking_uuid' => $slot->booking_uuid,
                    'is_local_booking' => $slot->isBookedLocally(),
                    'raw_payload' => $payload,
                ],
            );
        });
    }

    protected function deduplicateOverlappingSlots(Collection $slots): Collection
    {
        return $slots
            ->groupBy(function (OnecSlot $slot) {
                return implode('|', [
                    $slot->clinic_id ?? 0,
                    $slot->branch_id ?? 0,
                    $slot->doctor_id ?? 0,
                    $slot->cabinet_id ?? 0,
                    $slot->start_at?->format('Y-m-d H:i:s') ?? '',
                    $slot->end_at?->format('Y-m-d H:i:s') ?? '',
                ]);
            })
            ->map(function (Collection $group) {
                return $group
                    ->sort(function (OnecSlot $left, OnecSlot $right) {
                        $leftBooked = $left->status === OnecSlot::STATUS_BOOKED;
                        $rightBooked = $right->status === OnecSlot::STATUS_BOOKED;

                        if ($leftBooked !== $rightBooked) {
                            return $leftBooked ? -1 : 1;
                        }

                        $leftSyncedAt = optional($left->synced_at)->getTimestamp() ?? 0;
                        $rightSyncedAt = optional($right->synced_at)->getTimestamp() ?? 0;

                        if ($leftSyncedAt !== $rightSyncedAt) {
                            return $leftSyncedAt > $rightSyncedAt ? -1 : 1;
                        }

                        if ($left->id === $right->id) {
                            return 0;
                        }

                        return $left->id > $right->id ? -1 : 1;
                    })
                    ->first();
            })
            ->values();
    }
}
