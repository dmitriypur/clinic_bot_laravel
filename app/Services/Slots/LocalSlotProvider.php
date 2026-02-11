<?php

namespace App\Services\Slots;

use App\Models\Clinic;
use App\Models\DoctorShift;
use App\Models\User;
use App\Services\CalendarFilterService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class LocalSlotProvider implements SlotProviderInterface
{
    public function __construct(
        private readonly Clinic $clinic,
        private readonly CalendarFilterService $filterService,
    ) {}

    public function getSlots(CarbonInterface $from, CarbonInterface $to, array $filters, User $user): Collection
    {
        $query = DoctorShift::query()
            ->with(['doctor', 'cabinet.branch.clinic'])
            ->whereHas('cabinet.branch', function ($q) {
                $q->where('clinic_id', $this->clinic->id);
            })
            ->optimizedDateRange($from, $to);

        $filtersWithClinic = $filters;
        $filtersWithClinic['clinic_ids'] = array_unique(
            array_merge($filtersWithClinic['clinic_ids'] ?? [], [$this->clinic->id])
        );

        $this->filterService->applyShiftFilters($query, $filtersWithClinic, $user);

        $shifts = $query->get();

        $timezone = config('app.timezone', 'UTC');

        $results = collect();

        foreach ($shifts as $shift) {
            $slots = $shift->getTimeSlots();

            foreach ($slots as $slot) {
                $startApp = $slot['start'] instanceof Carbon
                    ? $slot['start']->copy()
                    : Carbon::parse($slot['start'], $timezone);

                $endApp = $slot['end'] instanceof Carbon
                    ? $slot['end']->copy()
                    : Carbon::parse($slot['end'], $timezone);

                $startUtc = $startApp->copy()->setTimezone('UTC');
                $endUtc = $endApp->copy()->setTimezone('UTC');

                $results->push(new SlotData(
                    id: 'local:'.$shift->id.':'.$startUtc->format('YmdHis'),
                    start: $startUtc,
                    end: $endUtc,
                    clinicId: $this->clinic->id,
                    branchId: $shift->cabinet?->branch_id,
                    cabinetId: $shift->cabinet_id,
                    doctorId: $shift->doctor_id,
                    source: 'local',
                    externallyOccupied: false,
                    meta: [
                        'shift_id' => $shift->id,
                        'doctor_name' => $shift->doctor?->full_name,
                        'branch_name' => $shift->cabinet?->branch?->name,
                        'cabinet_name' => $shift->cabinet?->name,
                        'clinic_name' => $shift->cabinet?->branch?->clinic?->name,
                        'branch_id' => $shift->cabinet?->branch_id,
                        'cabinet_id' => $shift->cabinet_id,
                        'city_id' => $shift->cabinet?->branch?->city_id,
                        'city_name' => $shift->cabinet?->branch?->city?->name,
                        'slot_display' => $slot['formatted'] ?? null,
                    ],
                ));
            }
        }

        return $results;
    }
}
