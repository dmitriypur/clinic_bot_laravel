<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Services\DoctorBranchesAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorBranchesAvailabilityController extends Controller
{
    public function __construct(
        private readonly DoctorBranchesAvailabilityService $doctorBranchesAvailabilityService,
    ) {
    }

    public function __invoke(Request $request, Doctor $doctor): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinics,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        return response()->json(
            $this->doctorBranchesAvailabilityService->getAvailability(
                doctor: $doctor,
                date: $validated['date'],
                clinicId: isset($validated['clinic_id']) ? (int) $validated['clinic_id'] : null,
                cityId: isset($validated['city_id']) ? (int) $validated['city_id'] : null,
            )
        );
    }
}
