<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\AvailabilitySearchRequest;
use App\Services\Booking\AvailabilityCriteria;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingIntegrationController extends Controller
{
    public function catalog(Request $request, CatalogService $catalog): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:doctor,branch,city,clinic'],
            'q' => ['required', 'string', 'max:120', 'regex:/[\p{L}\p{N}]/u'],
            'city_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'limit' => ['sometimes', 'required', 'integer', 'between:1,50'],
        ]);

        return response()->json($catalog->search(
            $data['type'], $data['q'], $data['city_id'] ?? null, $data['limit'] ?? 20,
            $request->attributes->get('booking_integration_branch_ids'),
        ), headers: ['Cache-Control' => 'no-store']);
    }

    public function availability(AvailabilitySearchRequest $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validated();
        $criteria = new AvailabilityCriteria(
            cityId: $data['city_id'] ?? null,
            clinicIds: $data['clinic_ids'] ?? [],
            branchIds: $data['branch_ids'] ?? [],
            doctorIds: $data['doctor_ids'] ?? [],
            specialty: $data['specialty'] ?? null,
            dateFrom: $data['date_from'] ?? null,
            dateTo: $data['date_to'] ?? null,
            timeFrom: $data['time_from'] ?? '00:00',
            timeTo: $data['time_to'] ?? '23:59',
            patientAge: $data['patient_age'] ?? null,
            birthDate: $data['birth_date'] ?? null,
            limit: $data['limit'] ?? 20,
        );
        $result = $availability->search($criteria, $request->attributes->get('booking_integration_branch_ids'));
        $status = $result['meta']['schedule_status'] === 'schedule_unavailable' ? 503 : 200;

        return response()->json($result, $status, ['Cache-Control' => 'no-store']);
    }
}
