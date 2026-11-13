<?php

namespace App\Services\Booking;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use App\Services\Slots\SlotData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function search(AvailabilityCriteria $criteria, ?array $allowedBranchIds = null): array
    {
        $branches = $this->catalog->branches($criteria->cityId, $criteria->clinicIds, $criteria->branchIds, $allowedBranchIds);
        if (array_diff($criteria->branchIds, $branches->modelKeys()) !== []) {
            throw ValidationException::withMessages([
                'branch_ids' => 'Один или несколько филиалов недоступны либо не относятся к выбранному городу или клинике.',
            ]);
        }

        $this->catalog->loadDoctors($branches, $criteria->doctorIds);
        foreach ($branches as $branch) {
            $branch->setRelation('doctors', $branch->doctors
                ->filter(fn (Doctor $doctor) => $this->matchesDoctor($doctor, $criteria))
                ->keyBy('id'));
        }
        $branches = $branches->filter(fn (Branch $branch) => $branch->doctors->isNotEmpty());

        $now = CarbonImmutable::now('UTC');
        $unverified = [];
        $hasVerifiedSchedule = false;
        $hasScheduledTime = false;
        $matches = collect();

        foreach ($branches->groupBy(fn (Branch $branch) => $branch->bookingTimezone(), preserveKeys: true) as $timezone => $group) {
            $today = $now->setTimezone($timezone)->startOfDay();
            $requestedFrom = CarbonImmutable::parse($criteria->dateFrom ?? $today->toDateString(), $timezone)->startOfDay();
            $until = CarbonImmutable::parse($criteria->dateTo ?? $requestedFrom->toDateString(), $timezone)->startOfDay()->addDay();
            $from = $requestedFrom->max($today);

            if ($from >= $until) {
                continue;
            }

            $localBranches = $group->reject(fn (Branch $branch) => $branch->isOnecPushMode());
            $onecBranches = $group->filter(fn (Branch $branch) => $branch->isOnecPushMode());
            $localSlots = $this->localSlots($localBranches, $from, $until);
            [$onecSlots, $unverifiedDates, $onecVerified, $onecScheduled] = $this->onecSlots($onecBranches, $from, $until, $now, $criteria->birthDate);
            array_push($unverified, ...$unverifiedDates);
            $hasVerifiedSchedule = $hasVerifiedSchedule || $localBranches->isNotEmpty() || $onecVerified;
            $hasScheduledTime = $hasScheduledTime || $onecScheduled;

            $occupied = $this->occupancy($group, $from, $until);
            foreach ($localSlots->concat($onecSlots) as $slot) {
                $branch = $group[$slot->branchId];
                $doctor = $branch->doctors[$slot->doctorId];
                $start = $slot->start->copy()->setTimezone($timezone);
                $time = $start->format('H:i');
                $utc = $start->copy()->utc()->format('Y-m-d H:i:s');

                if ($start < $from || $start >= $until || $slot->end <= $slot->start
                    || ! $this->admitsOnDate($doctor, $start, $criteria->birthDate)) {
                    continue;
                }

                $hasScheduledTime = true;
                if ($start < $now
                    || $time < $criteria->timeFrom || $time > $criteria->timeTo
                    || isset($occupied['cabinet'][$slot->cabinetId][$utc])
                    || isset($occupied['doctor'][$slot->doctorId][$utc])) {
                    continue;
                }

                $matches->push($slot);
            }
        }

        $matches = $matches->sortBy(fn (SlotData $slot) => [
            $slot->start->getTimestamp(), $slot->branchId, $slot->doctorId, $slot->id,
        ])->unique(fn (SlotData $slot) => $slot->branchId.'|'.$slot->doctorId.'|'.$slot->start->getTimestamp());

        $status = match (true) {
            $unverified === [] => 'complete',
            $hasVerifiedSchedule || $matches->isNotEmpty() => 'partial',
            default => 'schedule_unavailable',
        };
        $emptyReason = $status === 'complete' && $matches->isEmpty()
            ? ['empty_reason' => $hasScheduledTime ? 'no_available_slots' : 'no_schedule']
            : [];

        return [
            'data' => $matches->take($criteria->limit)->values()->map(fn (SlotData $slot) => $this->slotDetails($slot, $branches[$slot->branchId]))->all(),
            'meta' => [
                'schedule_status' => $status,
                'checked_at' => $now->toIso8601String(),
                'unverified_branches' => $unverified,
                'has_more' => $matches->count() > $criteria->limit,
            ] + $emptyReason,
        ];
    }

    private function matchesDoctor(Doctor $doctor, AvailabilityCriteria $criteria): bool
    {
        return ($criteria->specialty === null || $this->catalog->matchesSpecialty($doctor->specialty, $criteria->specialty))
            && ($criteria->patientAge === null || $doctor->admitsAge($criteria->patientAge));
    }

    private function admitsOnDate(Doctor $doctor, CarbonInterface $date, ?string $birthDate): bool
    {
        if ($birthDate === null) {
            return true;
        }

        $birth = CarbonImmutable::parse($birthDate, $date->getTimezone());

        return $date >= $birth && $doctor->admitsAge((int) $birth->diffInYears($date->copy()->startOfDay()));
    }

    /** @return Collection<int, SlotData> */
    private function localSlots(EloquentCollection $branches, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        if ($branches->isEmpty()) {
            return collect();
        }

        $shifts = DoctorShift::query()
            ->with('cabinet.branch.clinic')
            ->whereHas('cabinet', fn ($query) => $query->where('status', 1)->whereIn('branch_id', $branches->modelKeys()))
            ->whereIn('doctor_id', $this->doctorIds($branches))
            ->where('start_time', '<', $until->utc())
            ->where('end_time', '>', $from->utc())
            ->get();

        return $shifts->toBase()->flatMap(function (DoctorShift $shift) use ($branches): array {
            $branch = $branches[$shift->cabinet->branch_id];
            if (! $branch->doctors->has($shift->doctor_id)) {
                return [];
            }

            return array_map(fn (array $slot) => new SlotData(
                id: 'local:'.$shift->id.':'.$slot['start']->getTimestamp(),
                start: $slot['start'],
                end: $slot['end'],
                clinicId: $branch->clinic_id,
                branchId: $branch->id,
                cabinetId: $shift->cabinet_id,
                doctorId: $shift->doctor_id,
                source: 'local',
            ), $shift->getTimeSlots());
        });
    }

    private function onecSlots(
        EloquentCollection $branches,
        CarbonImmutable $from,
        CarbonImmutable $until,
        CarbonImmutable $now,
        ?string $birthDate,
    ): array {
        if ($branches->isEmpty()) {
            return [collect(), [], false, false];
        }

        // Imports store 1C wall time in the application zone. Local shifts and applications use UTC.
        $appTimezone = config('app.timezone');
        $rows = OnecSlot::query()
            ->whereIn('branch_id', $branches->modelKeys())
            ->whereIn('doctor_id', $this->doctorIds($branches))
            ->where('start_at', '>=', $from->setTimezone($appTimezone))
            ->where('start_at', '<', $until->setTimezone($appTimezone))
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get(['id', 'clinic_id', 'branch_id', 'doctor_id', 'cabinet_id', 'start_at', 'end_at', 'status', 'synced_at']);

        $maxAgeByBranch = config('booking-integrations.onec_max_age_by_branch');
        $defaultMaxAge = config('booking-integrations.onec_max_age_minutes');
        $cutoffs = $branches->mapWithKeys(function (Branch $branch) use ($now, $maxAgeByBranch, $defaultMaxAge): array {
            $maxAge = (int) ($maxAgeByBranch[$branch->id] ?? $defaultMaxAge);

            return [$branch->id => $now->subMinutes(max(1, $maxAge))];
        });
        $coverage = [];
        $candidates = collect();

        foreach ($rows as $row) {
            $branch = $branches[$row->branch_id];
            if ((int) $row->clinic_id !== $branch->clinic_id || ! $branch->doctors->has($row->doctor_id)) {
                continue;
            }

            $start = $row->start_at->copy()->setTimezone($branch->bookingTimezone());
            $date = $start->toDateString();
            $fresh = $row->synced_at !== null && $row->synced_at >= $cutoffs[$branch->id];
            $coverage[$branch->id][$date][$row->doctor_id] = $fresh && ($coverage[$branch->id][$date][$row->doctor_id] ?? true);
            $key = implode('|', [$row->branch_id, $row->doctor_id, $row->cabinet_id, intdiv($start->getTimestamp(), 60)]);

            // A booked or blocked duplicate takes precedence over a free row at the same start.
            if (! $row->isFree()) {
                $candidates[$key] = null;
            } elseif ($fresh && ! $candidates->has($key)) {
                $candidates[$key] = new SlotData(
                    id: 'onec:'.$row->id,
                    start: $row->start_at,
                    end: $row->end_at,
                    clinicId: $branch->clinic_id,
                    branchId: $branch->id,
                    cabinetId: $row->cabinet_id,
                    doctorId: $row->doctor_id,
                    source: 'onec',
                );
            }
        }

        $unverified = [];
        $hasVerified = false;
        $hasScheduledTime = false;
        foreach ($branches as $branch) {
            $unverifiedDates = [];
            for ($date = $from; $date < $until; $date = $date->addDay()) {
                $day = $date->toDateString();
                foreach ($branch->doctors as $doctor) {
                    if (! $this->admitsOnDate($doctor, $date, $birthDate)) {
                        continue;
                    }

                    $fresh = $coverage[$branch->id][$day][$doctor->id] ?? null;
                    $hasScheduledTime = $hasScheduledTime || $fresh !== null;
                    if ($fresh === false) {
                        $unverifiedDates[$day] = true;
                    } else {
                        $hasVerified = true;
                    }
                }
            }
            if ($unverifiedDates !== []) {
                $unverified[] = ['branch_id' => $branch->id, 'dates' => array_keys($unverifiedDates)];
            }
        }

        return [$candidates->filter(), $unverified, $hasVerified, $hasScheduledTime];
    }

    private function occupancy(EloquentCollection $branches, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $applications = Application::query()
            ->where('appointment_datetime', '>=', $from->utc())
            ->where('appointment_datetime', '<', $until->utc())
            ->where(fn ($query) => $query->whereIn('doctor_id', $this->doctorIds($branches))
                ->orWhereIn('cabinet_id', Cabinet::query()->select('id')->whereIn('branch_id', $branches->modelKeys())))
            ->get(['doctor_id', 'cabinet_id', 'appointment_datetime']);

        $occupied = [];
        foreach ($applications as $application) {
            $key = $application->getRawOriginal('appointment_datetime');
            if ($application->cabinet_id !== null) {
                $occupied['cabinet'][$application->cabinet_id][$key] = true;
            }
            if ($application->doctor_id !== null) {
                $occupied['doctor'][$application->doctor_id][$key] = true;
            }
        }

        return $occupied;
    }

    private function doctorIds(EloquentCollection $branches): Collection
    {
        return $branches->flatMap->doctors->pluck('id')->unique()->values();
    }

    private function slotDetails(SlotData $slot, Branch $branch): array
    {
        $timezone = $branch->bookingTimezone();
        $doctor = $branch->doctors[$slot->doctorId];

        return [
            'id' => $slot->id,
            'doctor' => ['id' => $doctor->id, 'name' => $doctor->full_name, 'specialty' => $doctor->specialty],
            'branch' => $this->catalog->branchDetails($branch),
            'starts_at' => $slot->start->copy()->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $slot->end->copy()->setTimezone($timezone)->toIso8601String(),
            'timezone' => $timezone,
        ];
    }
}
