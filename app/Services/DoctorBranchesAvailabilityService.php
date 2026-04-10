<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DoctorBranchesAvailabilityService
{
    private const CACHE_TTL_SECONDS = 120;

    public function getAvailability(
        Doctor $doctor,
        string $date,
        ?int $clinicId = null,
        ?int $cityId = null,
    ): array {
        $appTimezone = config('app.timezone', 'UTC');
        $selectedDate = Carbon::createFromFormat('Y-m-d', $date, $appTimezone);
        $dayStartUtc = $selectedDate->copy()->startOfDay()->setTimezone('UTC');
        $dayEndUtc = $selectedDate->copy()->endOfDay()->setTimezone('UTC');
        $now = Carbon::now($appTimezone);

        $branches = Branch::query()
            ->with('clinic:id,name,integration_mode,status')
            ->where('status', 1)
            ->when($clinicId, fn ($query) => $query->where('clinic_id', $clinicId))
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->whereHas('doctors', fn ($query) => $query->where('doctors.id', $doctor->id))
            ->get(['id', 'clinic_id', 'city_id', 'name', 'address', 'phone', 'status', 'external_id', 'integration_mode']);

        if ($branches->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'default_branch_id' => null,
                ],
            ];
        }

        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $localBranchIds = $branches
            ->filter(fn (Branch $branch) => ! $branch->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $onecBranchIds = $branches
            ->filter(fn (Branch $branch) => $branch->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cacheKey = implode(':', [
            'doctor-branches-availability',
            'doctor', $doctor->id,
            'clinic', $clinicId ?: 'any',
            'city', $cityId ?: 'any',
            'date', $date,
            'v', $this->resolveVersion($doctor->id, $branchIds, $localBranchIds, $onecBranchIds, $dayStartUtc, $dayEndUtc),
        ]);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, []);
        }

        $items = $branches
            ->map(function (Branch $branch): array {
                return [
                    'id' => $branch->id,
                    'clinic_id' => $branch->clinic_id,
                    'city_id' => $branch->city_id,
                    'name' => $branch->name,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                    'external_id' => $branch->external_id,
                    'integration_mode' => $branch->integration_mode?->value ?? $branch->integration_mode,
                    'clinic_name' => $branch->clinic?->name,
                    'available_slots' => 0,
                    'first_available_time' => null,
                    'has_available_slots' => false,
                ];
            })
            ->keyBy(fn (array $branch) => (int) $branch['id']);

        if ($localBranchIds !== []) {
            $this->mergeLocalAvailability(
                items: $items,
                doctorId: $doctor->id,
                branchIds: $localBranchIds,
                dayStartUtc: $dayStartUtc,
                dayEndUtc: $dayEndUtc,
                selectedDate: $selectedDate,
                now: $now,
                appTimezone: $appTimezone,
            );
        }

        if ($onecBranchIds !== []) {
            $this->mergeOnecAvailability(
                items: $items,
                doctorId: $doctor->id,
                branchIds: $onecBranchIds,
                dayStartUtc: $dayStartUtc,
                dayEndUtc: $dayEndUtc,
                now: $now,
                appTimezone: $appTimezone,
            );
        }

        $sorted = $items
            ->values()
            ->sort(function (array $left, array $right): int {
                $leftAvailable = $left['has_available_slots'] ? 1 : 0;
                $rightAvailable = $right['has_available_slots'] ? 1 : 0;

                if ($leftAvailable !== $rightAvailable) {
                    return $rightAvailable <=> $leftAvailable;
                }

                $timeComparison = strcmp((string) ($left['first_available_time'] ?? '99:99'), (string) ($right['first_available_time'] ?? '99:99'));
                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            })
            ->values()
            ->all();

        $payload = [
            'data' => $sorted,
            'meta' => [
                'default_branch_id' => collect($sorted)->firstWhere('has_available_slots', true)['id'] ?? null,
            ],
        ];

        Cache::put($cacheKey, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    private function mergeLocalAvailability(
        $items,
        int $doctorId,
        array $branchIds,
        Carbon $dayStartUtc,
        Carbon $dayEndUtc,
        Carbon $selectedDate,
        Carbon $now,
        string $appTimezone,
    ): void {
        $shifts = DoctorShift::query()
            ->with('cabinet:id,branch_id')
            ->where('doctor_id', $doctorId)
            ->whereHas('cabinet', fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                $query->whereBetween('start_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhereBetween('end_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhere(function ($sub) use ($dayStartUtc, $dayEndUtc) {
                        $sub->where('start_time', '<=', $dayStartUtc)
                            ->where('end_time', '>=', $dayEndUtc);
                    });
            })
            ->orderBy('start_time')
            ->get(['id', 'doctor_id', 'cabinet_id', 'start_time', 'end_time']);

        if ($shifts->isEmpty()) {
            return;
        }

        $cabinetIds = $shifts->pluck('cabinet_id')->filter()->unique()->values();
        $occupiedMap = [];

        if ($cabinetIds->isNotEmpty()) {
            $occupiedAppointments = Application::query()
                ->whereIn('cabinet_id', $cabinetIds)
                ->whereBetween('appointment_datetime', [
                    $dayStartUtc->format('Y-m-d H:i:s'),
                    $dayEndUtc->format('Y-m-d H:i:s'),
                ])
                ->get(['cabinet_id', 'appointment_datetime']);

            foreach ($occupiedAppointments as $application) {
                if (! $application->appointment_datetime || ! $application->cabinet_id) {
                    continue;
                }

                $occupiedMap[$application->cabinet_id.'|'.$application->appointment_datetime->copy()->setTimezone('UTC')->format('Y-m-d H:i:s')] = true;
            }
        }

        $seenSlots = [];

        foreach ($shifts as $shift) {
            $branchId = $shift->cabinet?->branch_id;
            if (! $branchId || ! $items->has((int) $branchId)) {
                continue;
            }

            foreach ($shift->getTimeSlots() as $slot) {
                $slotStart = $slot['start'] instanceof Carbon
                    ? $slot['start']->copy()
                    : Carbon::parse($slot['start'], $appTimezone);

                if (! $slotStart->isSameDay($selectedDate) || $slotStart->lt($now)) {
                    continue;
                }

                $slotStartUtc = $slotStart->copy()->setTimezone('UTC');
                $occupiedKey = $shift->cabinet_id.'|'.$slotStartUtc->format('Y-m-d H:i:s');

                if (isset($occupiedMap[$occupiedKey])) {
                    continue;
                }

                $slotKey = $branchId.'|'.$shift->cabinet_id.'|'.$slotStart->format('Y-m-d H:i');
                if (isset($seenSlots[$slotKey])) {
                    continue;
                }

                $seenSlots[$slotKey] = true;

                $item = $items[(int) $branchId];
                $item['available_slots']++;
                $item['has_available_slots'] = true;
                $item['first_available_time'] = $this->minTime($item['first_available_time'], $slotStart->format('H:i'));
                $items[(int) $branchId] = $item;
            }
        }
    }

    private function mergeOnecAvailability(
        $items,
        int $doctorId,
        array $branchIds,
        Carbon $dayStartUtc,
        Carbon $dayEndUtc,
        Carbon $now,
        string $appTimezone,
    ): void {
        $slots = OnecSlot::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('start_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('start_at')
            ->get(['id', 'doctor_id', 'branch_id', 'cabinet_id', 'start_at', 'status']);

        if ($slots->isEmpty()) {
            return;
        }

        $seenSlots = [];

        foreach ($slots as $slot) {
            if (! $slot->start_at || ! $slot->branch_id || ! $items->has((int) $slot->branch_id)) {
                continue;
            }

            $startLocal = $slot->start_at->copy()->setTimezone($appTimezone);

            if ($startLocal->lt($now) || ! $slot->isFree()) {
                continue;
            }

            $slotKey = $slot->branch_id.'|'.($slot->cabinet_id ?? 'null').'|'.$startLocal->format('Y-m-d H:i');
            if (isset($seenSlots[$slotKey])) {
                continue;
            }

            $seenSlots[$slotKey] = true;

            $item = $items[(int) $slot->branch_id];
            $item['available_slots']++;
            $item['has_available_slots'] = true;
            $item['first_available_time'] = $this->minTime($item['first_available_time'], $startLocal->format('H:i'));
            $items[(int) $slot->branch_id] = $item;
        }
    }

    private function resolveVersion(
        int $doctorId,
        array $branchIds,
        array $localBranchIds,
        array $onecBranchIds,
        Carbon $dayStartUtc,
        Carbon $dayEndUtc,
    ): string {
        $shiftVersion = $localBranchIds !== []
            ? DoctorShift::query()
                ->where('doctor_id', $doctorId)
                ->whereHas('cabinet', fn ($query) => $query->whereIn('branch_id', $localBranchIds))
                ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                    $query->whereBetween('start_time', [$dayStartUtc, $dayEndUtc])
                        ->orWhereBetween('end_time', [$dayStartUtc, $dayEndUtc])
                        ->orWhere(function ($sub) use ($dayStartUtc, $dayEndUtc) {
                            $sub->where('start_time', '<=', $dayStartUtc)
                                ->where('end_time', '>=', $dayEndUtc);
                        });
                })
                ->max('updated_at')
            : null;

        $applicationVersion = Application::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('appointment_datetime', [
                $dayStartUtc->format('Y-m-d H:i:s'),
                $dayEndUtc->format('Y-m-d H:i:s'),
            ])
            ->max('updated_at');

        $onecVersion = $onecBranchIds !== []
            ? OnecSlot::query()
                ->where('doctor_id', $doctorId)
                ->whereIn('branch_id', $onecBranchIds)
                ->whereBetween('start_at', [$dayStartUtc, $dayEndUtc])
                ->max('synced_at')
            : null;

        return implode('|', [
            $shiftVersion ? (string) strtotime((string) $shiftVersion) : '0',
            $applicationVersion ? (string) strtotime((string) $applicationVersion) : '0',
            $onecVersion ? (string) strtotime((string) $onecVersion) : '0',
        ]);
    }

    private function minTime(?string $left, ?string $right): ?string
    {
        if (! $left) {
            return $right;
        }

        if (! $right) {
            return $left;
        }

        return strcmp($left, $right) <= 0 ? $left : $right;
    }
}
