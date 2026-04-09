<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\DoctorsByDateCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorDateCalendarController extends Controller
{
    public function __construct(
        private readonly DoctorsByDateCalendarService $doctorsByDateCalendarService,
    ) {
    }

    public function __invoke(Request $request, City $city): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
            'birth_date' => ['nullable', 'date'],
            'doctor_uuids' => ['nullable', 'string'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinics,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        return response()->json(
            $this->doctorsByDateCalendarService->getAvailability(
                city: $city,
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                birthDate: $validated['birth_date'] ?? null,
                doctorUuids: isset($validated['doctor_uuids'])
                    ? array_filter(array_map('trim', explode(',', (string) $validated['doctor_uuids'])))
                    : [],
                clinicId: isset($validated['clinic_id']) ? (int) $validated['clinic_id'] : null,
                branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            )
        );
    }
}
