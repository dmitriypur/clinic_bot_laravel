<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\AvailabilitySearchRequest;
use App\Services\Booking\AvailabilityCriteria;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingOffer;
use App\Services\Booking\CatalogService;
use App\Services\Booking\IntegrationBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    public function availability(AvailabilitySearchRequest $request, AvailabilityService $availability, BookingOffer $offers): JsonResponse
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
        $branches = $request->attributes->get('booking_integration_branch_ids');
        $result = $availability->search($criteria, $branches);
        if (in_array('bookings:write', $request->attributes->get('booking_integration_scopes'), true)) {
            $result = $offers->attach($result, $request->attributes->get('booking_integration_client_id'), $branches);
        }

        $status = $result['meta']['schedule_status'] === 'schedule_unavailable' ? 503 : 200;

        return response()->json($result, $status, ['Cache-Control' => 'no-store']);
    }

    public function book(Request $request, IntegrationBookingService $booking): JsonResponse
    {
        $data = $request->validate([
            'offer_token' => ['required', 'string', 'max:2048'],
            'full_name' => ['required', 'string', 'max:255', 'regex:/\A[\p{L}\p{M}\x27-]+(?:\s+[\p{L}\p{M}\x27-]+)+\z/u'],
            'full_name_parent' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:500'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['required', 'string', 'regex:/\A\+?[78][0-9]{10}\z/'],
            'confirmed' => ['required', 'boolean', 'accepted'],
            'privacy_consent' => ['required', 'boolean', 'accepted'],
        ]);
        $data['confirmed'] = true;
        $data['privacy_consent'] = true;
        $data['full_name_parent'] ??= null;
        if (($data['comment'] ?? null) === null) {
            unset($data['comment']);
        }
        $data['phone'] = '+7'.substr($data['phone'], -10);
        ksort($data);

        $header = Validator::validate(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'uuid:4']],
        );

        [$result, $status] = $booking->create(
            $request->attributes->get('booking_integration_client_id'),
            $request->attributes->get('booking_integration_branch_ids'),
            strtolower($header['idempotency_key']),
            $data,
        );

        return response()->json($result, $status, ['Cache-Control' => 'no-store']);
    }

    public function operation(Request $request, string $id, IntegrationBookingService $booking): JsonResponse
    {
        [$result, $status] = $booking->status(
            $request->attributes->get('booking_integration_client_id'),
            $request->attributes->get('booking_integration_branch_ids'),
            strtolower($id),
        );

        return response()->json($result, $status, ['Cache-Control' => 'no-store']);
    }
}
