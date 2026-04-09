<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Branch;
use App\Models\City;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DoctorsByDateCalendarService
{
    private const CACHE_TTL_SECONDS = 120;

    public function getAvailability(
        City $city,
        string $dateFrom,
        string $dateTo,
        ?string $birthDate = null,
        array $doctorUuids = [],
        ?int $clinicId = null,
        ?int $branchId = null,
    ): array {
        $appTimezone = config('app.timezone', 'UTC');
        $rangeStartLocal = Carbon::createFromFormat('Y-m-d', $dateFrom, $appTimezone)->startOfDay();
        $rangeEndLocal = Carbon::createFromFormat('Y-m-d', $dateTo, $appTimezone)->endOfDay();

        if ($rangeStartLocal->gt($rangeEndLocal)) {
            throw ValidationException::withMessages([
                'date_to' => ['Дата окончания периода не может быть раньше даты начала.'],
            ]);
        }

        $periodDays = $rangeStartLocal->copy()->startOfDay()->diffInDays($rangeEndLocal->copy()->startOfDay()) + 1;
        if ($periodDays > 31) {
            throw ValidationException::withMessages([
                'date_to' => ['Период календаря не может превышать 31 день.'],
            ]);
        }

        $age = $birthDate ? Carbon::parse($birthDate)->age : null;
        $normalizedDoctorUuids = collect($doctorUuids)
            ->map(fn ($uuid) => trim((string) $uuid))
            ->filter(fn (string $uuid) => $uuid !== '')
            ->unique()
            ->values()
            ->all();
        $rangeStartUtc = $rangeStartLocal->copy()->setTimezone('UTC');
        $rangeEndUtc = $rangeEndLocal->copy()->setTimezone('UTC');
        $now = Carbon::now($appTimezone);

        $branch = $branchId ? Branch::query()->with('clinic')->find($branchId) : null;

        if ($branch && (int) $branch->city_id !== (int) $city->id) {
            throw ValidationException::withMessages([
                'branch_id' => ['Указанный филиал не относится к выбранному городу.'],
            ]);
        }

        if ($branch && $clinicId && (int) $branch->clinic_id !== $clinicId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Указанный филиал не относится к выбранной клинике.'],
            ]);
        }

        $branches = Branch::query()
            ->with('clinic:id,name,integration_mode,status')
            ->where('city_id', $city->id)
            ->where('status', 1)
            ->when($clinicId, fn ($query) => $query->where('clinic_id', $clinicId))
            ->when($branchId, fn ($query) => $query->where('id', $branchId))
            ->get(['id', 'clinic_id', 'city_id', 'name', 'address', 'status', 'integration_mode']);

        if ($branches->isEmpty()) {
            return ['data' => $this->buildEmptyCalendar($rangeStartLocal, $rangeEndLocal)];
        }

        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $localBranchIds = $branches
            ->filter(fn (Branch $branchModel) => ! $branchModel->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $onecBranchIds = $branches
            ->filter(fn (Branch $branchModel) => $branchModel->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cacheKey = implode(':', [
            'doctors-by-date-calendar',
            'city', $city->id,
            'clinic', $clinicId ?: 'any',
            'branch', $branchId ?: 'any',
            'from', $dateFrom,
            'to', $dateTo,
            'age', $age !== null ? $age : 'any',
            'uuids', $normalizedDoctorUuids !== [] ? sha1(implode(',', $normalizedDoctorUuids)) : 'all',
            'v', $this->resolveVersion($branchIds, $localBranchIds, $onecBranchIds, $rangeStartUtc, $rangeEndUtc),
        ]);

        if (Cache::has($cacheKey)) {
            return ['data' => Cache::get($cacheKey, [])];
        }

        $calendar = $this->buildEmptyCalendarMap($rangeStartLocal, $rangeEndLocal);

        if ($localBranchIds !== []) {
            $this->mergeCalendarItems(
                $calendar,
                $this->buildLocalAvailability(
                    branchIds: $localBranchIds,
                    rangeStartUtc: $rangeStartUtc,
                    rangeEndUtc: $rangeEndUtc,
                    rangeStartLocal: $rangeStartLocal,
                    rangeEndLocal: $rangeEndLocal,
                    now: $now,
                    age: $age,
                    doctorUuids: $normalizedDoctorUuids,
                    appTimezone: $appTimezone
                )
            );
        }

        if ($onecBranchIds !== []) {
            $this->mergeCalendarItems(
                $calendar,
                $this->buildOnecAvailability(
                    branchIds: $onecBranchIds,
                    rangeStartUtc: $rangeStartUtc,
                    rangeEndUtc: $rangeEndUtc,
                    now: $now,
                    age: $age,
                    doctorUuids: $normalizedDoctorUuids,
                    appTimezone: $appTimezone
                )
            );
        }

        $data = array_values($calendar);
        $this->registerCalendarCacheKey($cacheKey);
        Cache::put($cacheKey, $data, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return ['data' => $data];
    }

    private function buildLocalAvailability(
        array $branchIds,
        Carbon $rangeStartUtc,
        Carbon $rangeEndUtc,
        Carbon $rangeStartLocal,
        Carbon $rangeEndLocal,
        Carbon $now,
        ?int $age,
        array $doctorUuids,
        string $appTimezone,
    ): array {
        $shifts = DoctorShift::query()
            ->with([
                'cabinet:id,branch_id',
                'cabinet.branch:id,clinic_id,city_id,name,address,status,integration_mode',
            ])
            ->whereHas('cabinet', fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->whereHas('doctor', function ($query) use ($age) {
                $query->where('doctors.status', 1);
                $this->applyDoctorAgeFilter($query, $age);
            })
            ->when($doctorUuids !== [], function ($query) use ($doctorUuids) {
                $query->whereHas('doctor', fn ($doctorQuery) => $doctorQuery->whereIn('doctors.uuid', $doctorUuids));
            })
            ->where(function ($query) use ($rangeStartUtc, $rangeEndUtc) {
                $query->whereBetween('start_time', [$rangeStartUtc, $rangeEndUtc])
                    ->orWhereBetween('end_time', [$rangeStartUtc, $rangeEndUtc])
                    ->orWhere(function ($sub) use ($rangeStartUtc, $rangeEndUtc) {
                        $sub->where('start_time', '<=', $rangeStartUtc)
                            ->where('end_time', '>=', $rangeEndUtc);
                    });
            })
            ->orderBy('start_time')
            ->get(['id', 'doctor_id', 'cabinet_id', 'start_time', 'end_time']);

        if ($shifts->isEmpty()) {
            return [];
        }

        $cabinetIds = $shifts->pluck('cabinet_id')->filter()->unique()->values();
        $occupiedMap = [];

        if ($cabinetIds->isNotEmpty()) {
            $occupiedAppointments = Application::query()
                ->whereIn('cabinet_id', $cabinetIds)
                ->whereBetween('appointment_datetime', [
                    $rangeStartUtc->format('Y-m-d H:i:s'),
                    $rangeEndUtc->format('Y-m-d H:i:s'),
                ])
                ->get(['cabinet_id', 'appointment_datetime']);

            foreach ($occupiedAppointments as $application) {
                if (! $application->appointment_datetime || ! $application->cabinet_id) {
                    continue;
                }

                $occupiedMap[$application->cabinet_id.'|'.$application->appointment_datetime->copy()->setTimezone('UTC')->format('Y-m-d H:i:s')] = true;
            }
        }

        $calendar = [];
        $seenSlots = [];

        foreach ($shifts as $shift) {
            $branch = $shift->cabinet?->branch;

            if (! $branch) {
                continue;
            }

            foreach ($shift->getTimeSlots() as $slot) {
                $slotStart = $slot['start'] instanceof Carbon
                    ? $slot['start']->copy()
                    : Carbon::parse($slot['start'], $appTimezone);

                if ($slotStart->lt($rangeStartLocal) || $slotStart->gt($rangeEndLocal) || $slotStart->lt($now)) {
                    continue;
                }

                $slotStartUtc = $slotStart->copy()->setTimezone('UTC');
                $occupiedKey = $shift->cabinet_id.'|'.$slotStartUtc->format('Y-m-d H:i:s');

                if (isset($occupiedMap[$occupiedKey])) {
                    continue;
                }

                $dateKey = $slotStart->format('Y-m-d');
                $doctorBranchKey = $shift->doctor_id.'|'.$branch->id;
                $uniqueSlotKey = $doctorBranchKey.'|'.$shift->cabinet_id.'|'.$slotStart->format('Y-m-d H:i');

                if (isset($seenSlots[$uniqueSlotKey])) {
                    continue;
                }

                $seenSlots[$uniqueSlotKey] = true;
                $this->touchCalendarEntry($calendar, $dateKey, $slotStart->format('H:i'), $doctorBranchKey);
            }
        }

        return $this->finalizeCalendar($calendar);
    }

    private function buildOnecAvailability(
        array $branchIds,
        Carbon $rangeStartUtc,
        Carbon $rangeEndUtc,
        Carbon $now,
        ?int $age,
        array $doctorUuids,
        string $appTimezone,
    ): array {
        $slots = OnecSlot::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('start_at', [$rangeStartUtc, $rangeEndUtc])
            ->whereHas('doctor', function ($query) use ($age) {
                $query->where('doctors.status', 1);
                $this->applyDoctorAgeFilter($query, $age);
            })
            ->when($doctorUuids !== [], function ($query) use ($doctorUuids) {
                $query->whereHas('doctor', fn ($doctorQuery) => $doctorQuery->whereIn('doctors.uuid', $doctorUuids));
            })
            ->orderBy('start_at')
            ->get(['id', 'doctor_id', 'branch_id', 'cabinet_id', 'start_at', 'status']);

        if ($slots->isEmpty()) {
            return [];
        }

        $calendar = [];
        $seenSlots = [];

        foreach ($slots as $slot) {
            if (! $slot->start_at) {
                continue;
            }

            $startLocal = $slot->start_at->copy()->setTimezone($appTimezone);

            if ($startLocal->lt($now) || ! $slot->isFree()) {
                continue;
            }

            $dateKey = $startLocal->format('Y-m-d');
            $doctorBranchKey = $slot->doctor_id.'|'.$slot->branch_id;
            $uniqueSlotKey = $doctorBranchKey.'|'.($slot->cabinet_id ?? 'null').'|'.$startLocal->format('Y-m-d H:i');

            if (isset($seenSlots[$uniqueSlotKey])) {
                continue;
            }

            $seenSlots[$uniqueSlotKey] = true;
            $this->touchCalendarEntry($calendar, $dateKey, $startLocal->format('H:i'), $doctorBranchKey);
        }

        return $this->finalizeCalendar($calendar);
    }

    private function touchCalendarEntry(array &$calendar, string $date, string $time, string $doctorBranchKey): void
    {
        if (! isset($calendar[$date])) {
            $calendar[$date] = [
                'date' => $date,
                'total_slots' => 0,
                'available_slots' => 0,
                'available_doctors' => [],
                'first_available_time' => null,
            ];
        }

        $calendar[$date]['total_slots']++;
        $calendar[$date]['available_slots']++;
        $calendar[$date]['available_doctors'][$doctorBranchKey] = true;

        if (
            $calendar[$date]['first_available_time'] === null
            || strcmp($time, $calendar[$date]['first_available_time']) < 0
        ) {
            $calendar[$date]['first_available_time'] = $time;
        }
    }

    private function finalizeCalendar(array $calendar): array
    {
        ksort($calendar);

        return collect($calendar)
            ->map(function (array $item): array {
                $item['available_doctors'] = count($item['available_doctors'] ?? []);
                return $item;
            })
            ->values()
            ->all();
    }

    private function mergeCalendarItems(array &$target, array $items): void
    {
        foreach ($items as $item) {
            $date = $item['date'] ?? null;

            if (! $date || ! isset($target[$date])) {
                continue;
            }

            $target[$date]['total_slots'] += (int) ($item['total_slots'] ?? 0);
            $target[$date]['available_slots'] += (int) ($item['available_slots'] ?? 0);
            $target[$date]['available_doctors'] += (int) ($item['available_doctors'] ?? 0);

            $candidateTime = $item['first_available_time'] ?? null;
            if (
                $candidateTime
                && (
                    $target[$date]['first_available_time'] === null
                    || strcmp($candidateTime, $target[$date]['first_available_time']) < 0
                )
            ) {
                $target[$date]['first_available_time'] = $candidateTime;
            }
        }
    }

    private function buildEmptyCalendar(Carbon $rangeStartLocal, Carbon $rangeEndLocal): array
    {
        return array_values($this->buildEmptyCalendarMap($rangeStartLocal, $rangeEndLocal));
    }

    private function buildEmptyCalendarMap(Carbon $rangeStartLocal, Carbon $rangeEndLocal): array
    {
        $calendar = [];
        $cursor = $rangeStartLocal->copy()->startOfDay();
        $last = $rangeEndLocal->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $calendar[$cursor->format('Y-m-d')] = [
                'date' => $cursor->format('Y-m-d'),
                'total_slots' => 0,
                'available_slots' => 0,
                'available_doctors' => 0,
                'first_available_time' => null,
            ];
            $cursor->addDay();
        }

        return $calendar;
    }

    private function resolveVersion(
        array $branchIds,
        array $localBranchIds,
        array $onecBranchIds,
        Carbon $rangeStartUtc,
        Carbon $rangeEndUtc,
    ): string {
        $doctorVersion = Doctor::query()->max('updated_at');

        $shiftVersion = $localBranchIds !== []
            ? DoctorShift::query()
                ->whereHas('cabinet', fn ($query) => $query->whereIn('branch_id', $localBranchIds))
                ->where(function ($query) use ($rangeStartUtc, $rangeEndUtc) {
                    $query->whereBetween('start_time', [$rangeStartUtc, $rangeEndUtc])
                        ->orWhereBetween('end_time', [$rangeStartUtc, $rangeEndUtc])
                        ->orWhere(function ($sub) use ($rangeStartUtc, $rangeEndUtc) {
                            $sub->where('start_time', '<=', $rangeStartUtc)
                                ->where('end_time', '>=', $rangeEndUtc);
                        });
                })
                ->max('updated_at')
            : null;

        $applicationVersion = Application::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('appointment_datetime', [
                $rangeStartUtc->format('Y-m-d H:i:s'),
                $rangeEndUtc->format('Y-m-d H:i:s'),
            ])
            ->max('updated_at');

        $onecVersion = $onecBranchIds !== []
            ? OnecSlot::query()
                ->whereIn('branch_id', $onecBranchIds)
                ->whereBetween('start_at', [$rangeStartUtc, $rangeEndUtc])
                ->max('synced_at')
            : null;

        return implode('|', [
            $doctorVersion ? (string) strtotime((string) $doctorVersion) : '0',
            $shiftVersion ? (string) strtotime((string) $shiftVersion) : '0',
            $applicationVersion ? (string) strtotime((string) $applicationVersion) : '0',
            $onecVersion ? (string) strtotime((string) $onecVersion) : '0',
        ]);
    }

    private function applyDoctorAgeFilter($query, ?int $age): void
    {
        if ($age === null) {
            return;
        }

        $query
            ->where(function ($builder) use ($age) {
                $builder->whereNull('age_admission_from')
                    ->orWhere('age_admission_from', '<=', $age);
            })
            ->where(function ($builder) use ($age) {
                $builder->whereNull('age_admission_to')
                    ->orWhere('age_admission_to', '>=', $age);
            });
    }

    private function registerCalendarCacheKey(string $cacheKey): void
    {
        $keys = Cache::get('calendar_cache_keys', []);
        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put('calendar_cache_keys', $keys, 3600);
        }
    }
}
