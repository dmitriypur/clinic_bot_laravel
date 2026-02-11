<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\Application;
use App\Models\Branch;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DoctorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $doctors = Doctor::where('status', 1)
            ->select([
                'id',
                'last_name',
                'first_name',
                'second_name',
                'experience',
                'age',
                'photo_src',
                'diploma_src',
                'status',
                'age_admission_from',
                'age_admission_to',
                'uuid',
                'review_link',
                'external_id',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return DoctorResource::collection($doctors);
    }

    public function byClinic(Request $request, Clinic $clinic)
    {
        $age = $this->getAge($request);

        $doctorsQuery = $clinic->doctors()
            ->where('doctors.status', 1);
        $branchId = $request->input('branch_id');
        if ($branchId) {
            $doctorsQuery->whereHas('branches', function ($query) use ($branchId, $clinic) {
                $query->where('branches.id', $branchId)
                    ->where('branches.clinic_id', $clinic->id);
            });
        }
        if ($age !== null) {
            $doctorsQuery->where('age_admission_from', '<=', $age)
                ->where('age_admission_to', '>=', $age);
        }

        $latestUpdate = Doctor::query()->max('updated_at');
        $versionStamp = $latestUpdate ? (string) strtotime((string) $latestUpdate) : '0';
        $cacheKey = 'doctors:by-clinic:'.$clinic->id
            .':'.($branchId ?: 'any')
            .':'.($age !== null ? $age : 'any')
            .':'.$versionStamp;

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $doctors = $doctorsQuery
            ->select(
                'doctors.id',
                'doctors.last_name',
                'doctors.first_name',
                'doctors.second_name',
                'doctors.experience',
                'doctors.age',
                'doctors.photo_src',
                'doctors.diploma_src',
                'doctors.status',
                'doctors.age_admission_from',
                'doctors.age_admission_to',
                'doctors.uuid',
                'doctors.external_id',
                'doctors.review_link'
            )
            ->orderBy('doctors.last_name')
            ->get();

        $payload = DoctorResource::collection($doctors)
            ->toResponse($request)
            ->getData(true);

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    public function byCity(Request $request, City $city)
    {
        $age = $this->getAge($request);

        $doctorsQuery = $city->allDoctors()
            ->where('doctors.status', 1);
        if ($age !== null) {
            $doctorsQuery->where('age_admission_from', '<=', $age)
                ->where('age_admission_to', '>=', $age);
        }

        $latestUpdate = Doctor::query()->max('updated_at');
        $versionStamp = $latestUpdate ? (string) strtotime((string) $latestUpdate) : '0';
        $cacheKey = 'doctors:by-city:'.$city->id
            .':'.($age !== null ? $age : 'any')
            .':'.$versionStamp;

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $doctors = $doctorsQuery
            ->select(
                'doctors.id',
                'doctors.last_name',
                'doctors.first_name',
                'doctors.second_name',
                'doctors.experience',
                'doctors.age',
                'doctors.photo_src',
                'doctors.diploma_src',
                'doctors.status',
                'doctors.age_admission_from',
                'doctors.age_admission_to',
                'doctors.uuid',
                'doctors.external_id',
                'doctors.review_link'
            )
            ->orderBy('doctors.last_name')
            ->get();

        $payload = DoctorResource::collection($doctors)
            ->toResponse($request)
            ->getData(true);

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    public function getAge(Request $request): ?int
    {
        $birthDate = $request->input('birth_date');

        if (! $birthDate) {
            return null;
        }

        return Carbon::parse($birthDate)->age;
    }

    public function slots(Request $request, Doctor $doctor)
    {
        $appTimezone = config('app.timezone', 'UTC');
        $dateInput = $request->input('date');
        $branchId = $request->input('branch_id');
        $clinicId = $request->input('clinic_id');

        try {
            $selectedDate = $dateInput
                ? Carbon::parse($dateInput, $appTimezone)
                : Carbon::now($appTimezone);
        } catch (\Throwable $exception) {
            $selectedDate = Carbon::now($appTimezone);
        }

        $dayStartUtc = $selectedDate->copy()->startOfDay()->setTimezone('UTC');
        $dayEndUtc = $selectedDate->copy()->endOfDay()->setTimezone('UTC');
        $now = Carbon::now($appTimezone);

        $branch = $branchId
            ? Branch::query()->with('clinic')->find($branchId)
            : null;
        $clinic = $this->resolveClinic($clinicId, $branch, $doctor);

        $isOnecMode = $clinic && $clinic->isOnecPushMode();

        if ($isOnecMode) {
            $onecVersion = OnecSlot::query()
                ->where('clinic_id', $clinic->id)
                ->whereBetween('start_at', [$dayStartUtc, $dayEndUtc])
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->where('doctor_id', $doctor->id)
                ->max('synced_at');

            $cacheKey = 'slots:onec:d:'.$doctor->id.':c:'.$clinic->id.':b:'.($branch?->id ?? 'any').':date:'.$selectedDate->format('Y-m-d').':'.($onecVersion ? (string) strtotime((string) $onecVersion) : '0');

            if ($cached = Cache::get($cacheKey)) {
                return response()->json(['data' => $cached]);
            }

            $slots = $this->buildOnecSlotsPayload(
                $doctor,
                $clinic,
                $branch,
                $dayStartUtc,
                $dayEndUtc,
                $selectedDate,
                $now,
                $appTimezone
            );

            $this->registerCalendarCacheKey($cacheKey);
            Cache::put($cacheKey, $slots, now()->addSeconds(120));

            return response()->json(['data' => $slots]);
        }

        $shiftsQuery = DoctorShift::query()
            ->with(['cabinet.branch.clinic'])
            ->where('doctor_id', $doctor->id)
            ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                $query->whereBetween('start_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhereBetween('end_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhere(function ($sub) use ($dayStartUtc, $dayEndUtc) {
                        $sub->where('start_time', '<=', $dayStartUtc)
                            ->where('end_time', '>=', $dayEndUtc);
                    });
            });

        if ($branchId) {
            $shiftsQuery->whereHas('cabinet', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            });
        }

        if ($clinicId) {
            $shiftsQuery->whereHas('cabinet.branch', function ($query) use ($clinicId) {
                $query->where('clinic_id', $clinicId);
            });
        }

        $shifts = $shiftsQuery->orderBy('start_time')->get();

        $slots = [];

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
                $appointmentStart = $application->appointment_datetime;

                if (! $appointmentStart) {
                    continue;
                }

                $appointmentStartUtc = $appointmentStart->copy()->setTimezone('UTC');
                $key = $application->cabinet_id.'|'.$appointmentStartUtc->format('Y-m-d H:i:s');

                $occupiedMap[$key] = true;
            }
        }

        foreach ($shifts as $shift) {
            $branchModel = $shift->cabinet->branch ?? null;
            $clinicModel = $branchModel?->clinic;
            foreach ($shift->getTimeSlots() as $slot) {
                $slotStart = $slot['start'] instanceof Carbon
                    ? $slot['start']->copy()
                    : Carbon::parse($slot['start'], $appTimezone);

                if (! $slotStart->isSameDay($selectedDate)) {
                    continue;
                }

                $slotStartUtc = $slotStart->copy()->setTimezone('UTC');
                $slotKey = $slotStartUtc->format('Y-m-d H:i:s');
                $occupiedKey = $shift->cabinet_id.'|'.$slotKey;

                $isPast = $slotStart->lt($now);
                $isOccupied = isset($occupiedMap[$occupiedKey]);

                $slots[] = [
                    'id' => $shift->id.'_'.$slotStart->format('His'),
                    'shift_id' => $shift->id,
                    'cabinet_id' => $shift->cabinet_id,
                    'branch_id' => $branchModel?->id,
                    'clinic_id' => $clinicModel?->id,
                    'branch_name' => $branchModel?->name,
                    'clinic_name' => $clinicModel?->name,
                    'cabinet_name' => $shift->cabinet->name ?? null,
                    'time' => $slotStart->format('H:i'),
                    'datetime' => $slotStart->format('Y-m-d H:i'),
                    'duration' => $slot['duration'],
                    'is_past' => $isPast,
                    'is_occupied' => $isOccupied,
                    'is_available' => ! $isPast && ! $isOccupied,
                    'onec_slot_id' => null,
                ];
            }
        }

        usort($slots, function ($a, $b) {
            return strcmp($a['datetime'], $b['datetime']);
        });

        $localVersionShift = DoctorShift::query()
            ->where('doctor_id', $doctor->id)
            ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                $query->whereBetween('start_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhereBetween('end_time', [$dayStartUtc, $dayEndUtc])
                    ->orWhere(function ($sub) use ($dayStartUtc, $dayEndUtc) {
                        $sub->where('start_time', '<=', $dayStartUtc)
                            ->where('end_time', '>=', $dayEndUtc);
                    });
            })
            ->max('updated_at');

        $localVersionAppsQuery = Application::query()
            ->whereBetween('appointment_datetime', [
                $dayStartUtc->format('Y-m-d H:i:s'),
                $dayEndUtc->format('Y-m-d H:i:s'),
            ])
            ->where('doctor_id', $doctor->id);

        if ($branchId) {
            $localVersionAppsQuery->where('branch_id', $branchId);
        }

        if ($clinicId) {
            $localVersionAppsQuery->where('clinic_id', $clinicId);
        }

        $localVersionApps = $localVersionAppsQuery->max('updated_at');

        $cacheKey = 'slots:local:d:'.$doctor->id.':c:'.($clinicId ?: 'any').':b:'.($branchId ?: 'any').':date:'.$selectedDate->format('Y-m-d').':'.implode('|', [
            $localVersionShift ? (string) strtotime((string) $localVersionShift) : '0',
            $localVersionApps ? (string) strtotime((string) $localVersionApps) : '0',
        ]);

        $this->registerCalendarCacheKey($cacheKey);
        Cache::put($cacheKey, $slots, now()->addSeconds(120));

        return response()->json(['data' => $slots]);
    }

    protected function resolveClinic(?int $clinicId, ?Branch $branch, Doctor $doctor): ?Clinic
    {
        if ($branch?->clinic) {
            return $branch->clinic;
        }

        if ($clinicId) {
            return Clinic::query()->find($clinicId);
        }

        return $doctor->clinics()->first();
    }

    protected function buildOnecSlotsPayload(
        Doctor $doctor,
        Clinic $clinic,
        ?Branch $branch,
        Carbon $dayStartUtc,
        Carbon $dayEndUtc,
        Carbon $selectedDate,
        Carbon $now,
        string $appTimezone
    ): array {
        $query = OnecSlot::query()
            ->with(['branch.city', 'branch.clinic', 'cabinet', 'clinic'])
            ->where('clinic_id', $clinic->id)
            ->whereBetween('start_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('start_at');

        $query->where('doctor_id', $doctor->id);

        if ($branch) {
            $query->where('branch_id', $branch->id);
        }

        $groupedSlots = [];

        foreach ($query->get() as $slot) {
            if (! $slot->start_at) {
                continue;
            }

            $branchModel = $slot->branch ?: $branch;
            $clinicModel = $branchModel?->clinic ?? $slot->clinic ?? $clinic;

            $startLocal = $slot->start_at->copy()->setTimezone($appTimezone);
            $endLocal = $slot->end_at
                ? $slot->end_at->copy()->setTimezone($appTimezone)
                : $startLocal->copy()->addMinutes(
                    $branchModel?->getEffectiveSlotDuration() ?? $clinic->getEffectiveSlotDuration()
                );

            if (! $startLocal->isSameDay($selectedDate)) {
                continue;
            }

            $duration = max(5, $startLocal->diffInMinutes($endLocal));
            $isPast = $startLocal->lt($now);
            $isOccupied = $slot->status !== OnecSlot::STATUS_FREE;

            $groupKey = implode('|', [
                $branchModel?->id ?? 'null',
                $slot->doctor_id ?? 'null',
                $slot->cabinet_id ?? 'null',
                $startLocal->format('Y-m-d H:i'),
            ]);

            if (isset($groupedSlots[$groupKey])) {
                if ($isOccupied && ! $groupedSlots[$groupKey]['is_occupied']) {
                    $groupedSlots[$groupKey]['is_occupied'] = true;
                    $groupedSlots[$groupKey]['is_available'] = false;
                    $groupedSlots[$groupKey]['onec_slot_id'] = null;
                }

                continue;
            }

            $groupedSlots[$groupKey] = [
                'id' => 'onec:'.$slot->id,
                'shift_id' => null,
                'cabinet_id' => $slot->cabinet_id,
                'branch_id' => $branchModel?->id,
                'clinic_id' => $clinicModel?->id,
                'branch_name' => $branchModel?->name,
                'clinic_name' => $clinicModel?->name ?? $clinic->name,
                'cabinet_name' => $slot->cabinet?->name,
                'time' => $startLocal->format('H:i'),
                'datetime' => $startLocal->format('Y-m-d H:i'),
                'duration' => $duration,
                'is_past' => $isPast,
                'is_occupied' => $isOccupied,
                'is_available' => ! $isPast && ! $isOccupied,
                'onec_slot_id' => $slot->external_slot_id,
            ];
        }

        return array_values($groupedSlots);
    }

    protected function registerCalendarCacheKey(string $cacheKey): void
    {
        $keys = Cache::get('calendar_cache_keys', []);
        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put('calendar_cache_keys', $keys, 3600);
        }
    }
}
