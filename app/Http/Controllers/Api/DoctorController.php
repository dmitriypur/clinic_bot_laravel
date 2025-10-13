<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\Application;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DoctorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $doctors = Doctor::where('status', 1)->with(['applications', 'clinics', 'reviews'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        return DoctorResource::collection($doctors);
    }

    public function byClinic(Request $request, Clinic $clinic)
    {
        $age = $this->getAge($request);

        $doctorsQuery = $clinic->doctors();
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

        return DoctorResource::collection($doctorsQuery->get());
    }

    public function byCity(Request $request, City $city)
    {
        $age = $this->getAge($request);

        $doctorsQuery = $city->allDoctors();
        if ($age !== null) {
            $doctorsQuery->where('age_admission_from', '<=', $age)
                ->where('age_admission_to', '>=', $age);
        }

        return DoctorResource::collection($doctorsQuery->get());
    }

    public function getAge(Request $request): ?int
    {
        $birthDate = $request->input('birth_date');

        if (!$birthDate) {
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

        $shifts = $shiftsQuery->get();

        $now = Carbon::now($appTimezone);
        $slots = [];

        foreach ($shifts as $shift) {
            $branch = $shift->cabinet->branch ?? null;
            $clinic = $branch?->clinic;
            foreach ($shift->getTimeSlots() as $slot) {
                $slotStart = $slot['start'] instanceof Carbon
                    ? $slot['start']->copy()
                    : Carbon::parse($slot['start'], $appTimezone);

                if (!$slotStart->isSameDay($selectedDate)) {
                    continue;
                }

                $slotStartUtc = $slotStart->copy()->setTimezone('UTC');
                $slotKey = $slotStartUtc->format('Y-m-d H:i:s');

                $isPast = $slotStart->lt($now);

                $isOccupied = Application::query()
                    ->where('cabinet_id', $shift->cabinet_id)
                    ->where('appointment_datetime', $slotKey)
                    ->exists();

                $slots[] = [
                    'id' => $shift->id . '_' . $slotStart->format('His'),
                    'shift_id' => $shift->id,
                    'cabinet_id' => $shift->cabinet_id,
                    'branch_id' => $branch?->id,
                    'clinic_id' => $clinic?->id,
                    'branch_name' => $branch?->name,
                    'clinic_name' => $clinic?->name,
                    'cabinet_name' => $shift->cabinet->name ?? null,
                    'time' => $slotStart->format('H:i'),
                    'datetime' => $slotStart->format('Y-m-d H:i'),
                    'duration' => $slot['duration'],
                    'is_past' => $isPast,
                    'is_occupied' => $isOccupied,
                    'is_available' => !$isPast && !$isOccupied,
                ];
            }
        }

        usort($slots, function ($a, $b) {
            return strcmp($a['datetime'], $b['datetime']);
        });

        return response()->json([
            'data' => $slots,
        ]);
    }


}
