<?php

namespace App\Services\Booking;

use App\Models\Application;
use App\Models\BookingOperation;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use App\Services\OneC\Exceptions\OneCApiException;
use App\Services\OneC\Exceptions\OneCBookingException;
use App\Services\OneC\OneCBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationBookingService
{
    public function __construct(
        private readonly BookingOffer $offers,
        private readonly AvailabilityService $availability,
        private readonly OneCBookingService $onec,
        private readonly LocalBooking $local,
    ) {}

    public function create(string $clientId, ?array $allowedBranchIds, ?string $key, array $data): array
    {
        $offer = $this->offers->read($data['offer_token'], $clientId, $allowedBranchIds);
        $key ??= $offer['operation_id'] ?? throw ValidationException::withMessages([
            'idempotency_key' => 'Передайте Idempotency-Key или заново найдите свободное окно.',
        ]);

        $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), config('app.key'));
        $existing = $this->operation($clientId, $key);
        if ($existing) {
            abort_unless($this->offers->allowsBranch((int) $existing->branch_id, $allowedBranchIds), 404);

            return $this->replay($existing, $hash);
        }

        if ($offer['expires'] <= now()->timestamp) {
            return [['code' => 'offer_expired'], 409];
        }

        $start = CarbonImmutable::parse($offer['start']);
        $end = CarbonImmutable::parse($offer['end']);
        if (str_starts_with($offer['slot'], 'local:')) {
            return $this->createLocal($clientId, $key, $hash, $offer, $start, $end, $data);
        }

        $slot = $this->offers->slot($offer);
        [$operation, $slot] = DB::transaction(function () use ($clientId, $key, $hash, $offer, $slot, $start, $end, $data) {
            // Insert first so SQLite obtains its write lock before taking a read snapshot.
            $operation = $this->startOperation($clientId, $key, $hash, $offer, $slot->cabinet_id, $start, $end);
            if (! $operation->wasRecentlyCreated) {
                return [$operation, null];
            }

            $doctor = Doctor::query()->lockForUpdate()->findOrFail($offer['doctor']);
            if ($slot->cabinet_id !== null) {
                Cabinet::query()->lockForUpdate()->findOrFail($slot->cabinet_id);
            }
            $current = OnecSlot::query()->lockForUpdate()->find($slot->id);
            if (! $current || $current->cabinet_id !== $slot->cabinet_id
                || (int) $current->branch_id !== $offer['branch'] || (int) $current->doctor_id !== $offer['doctor']) {
                $operation->reject('slot_unavailable', 409);

                return [$operation, null];
            }

            $rejection = $this->checkWindow($operation, $offer, $current, $doctor, $start, $end, $data['birth_date']);
            if ($rejection) {
                $operation->reject(...$rejection);

                return [$operation, null];
            }

            return [$operation, $current];
        }, 3);

        if (! $operation->wasRecentlyCreated) {
            return $this->replay($operation, $hash);
        }
        if ($slot === null) {
            return $this->result($operation);
        }

        $application = new Application([
            'city_id' => $slot->branch->city_id, 'clinic_id' => $slot->clinic_id, 'branch_id' => $slot->branch_id,
            'doctor_id' => $slot->doctor_id, 'cabinet_id' => $slot->cabinet_id, 'appointment_datetime' => $slot->start_at,
            'full_name' => $data['full_name'], 'full_name_parent' => $data['full_name_parent'],
            'birth_date' => $data['birth_date'], 'phone' => $data['phone'], 'send_to_1c' => true,
            'submission_source' => $clientId, 'submission_type' => Application::SUBMISSION_TYPE_APPOINTMENT_FORM,
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
        ]);

        try {
            $reply = $this->onec->requestSelectedSlot($application, $slot, $operation->onec_request_id, $data['comment'] ?? null);
        } catch (OneCApiException $exception) {
            $context = $exception->context();
            if (($context['status'] ?? null) === 409 && data_get($context, 'body.code') === 'slot_unavailable') {
                $operation->reject('slot_unavailable', 409);
            }

            return $this->result($operation);
        } catch (OneCBookingException) {
            $operation->reject('source_unavailable', 503);

            return $this->result($operation);
        }

        $externalId = $reply['claim_id'] ?? $reply['appointment_id'] ?? null;
        $bodyStatus = filter_var($reply['status_code'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($reply['http_status'], [200, 201], true)
            && in_array($reply['status'] ?? null, ['fail', 'failed', 'error'], true)
            && blank($reply['claim_id'] ?? null) && blank($reply['appointment_id'] ?? null)) {
            $operation->reject('source_rejected', 422);

            return $this->result($operation);
        }
        if (! in_array($reply['http_status'], [200, 201], true)
            || ! in_array($bodyStatus, [200, 201], true)
            || ! in_array($reply['status'] ?? null, ['success', 'booked', 'confirmed'], true)
            || ! is_string($externalId) || trim($externalId) === '' || strlen($externalId) > 191) {
            return $this->result($operation);
        }

        try {
            DB::transaction(function () use ($application, $operation, $slot, $externalId) {
                $existing = Application::query()->where('branch_id', $slot->branch_id)
                    ->where('integration_type', Application::INTEGRATION_TYPE_ONEC)
                    ->where('external_appointment_id', $externalId)->first();
                if (! $existing) {
                    $application->fill(['external_appointment_id' => $externalId, 'integration_status' => 'booked']);
                    Application::withoutEvents(fn () => $application->save());
                }
                $slot->update(['status' => OnecSlot::STATUS_BOOKED, 'booking_uuid' => $externalId, 'synced_at' => now()]);
                $operation->update(['status' => 'confirmed', 'application_id' => $existing?->id ?? $application->id]);
            });
        } catch (QueryException) {
            Log::error('Could not persist a confirmed 1C booking.', ['booking_operation_id' => $operation->id]);

            return [['operation_id' => $operation->operation_key, 'status' => 'unknown'], 202];
        }

        return $this->result($operation, created: true);
    }

    private function createLocal(
        string $clientId,
        string $key,
        string $hash,
        array $offer,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $data,
    ): array {
        [, $shiftId] = explode(':', $offer['slot']);
        $cabinetId = DoctorShift::query()->whereKey($shiftId)->value('cabinet_id');

        $operation = DB::transaction(function () use ($clientId, $key, $hash, $offer, $shiftId, $cabinetId, $start, $end, $data) {
            $operation = $this->startOperation($clientId, $key, $hash, $offer, $cabinetId, $start, $end);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }

            $doctor = $this->local->lockResources($offer['doctor'], $cabinetId);
            $shift = DoctorShift::query()->lockForUpdate()->find($shiftId);
            $branch = $shift?->cabinet?->branch;
            if ($offer['expires'] <= now()->timestamp) {
                $operation->reject('offer_expired', 409);

                return $operation;
            }
            if (! $doctor || $branch?->id !== $offer['branch'] || $shift->cabinet_id !== $cabinetId
                || (int) $shift->doctor_id !== $offer['doctor'] || $start <= now()
                || ! $this->offers->usesLocalBooking($branch)) {
                $operation->reject('slot_unavailable', 409);

                return $operation;
            }

            $birth = CarbonImmutable::parse($data['birth_date'], $start->getTimezone());
            if (! $doctor->admitsAge((int) $birth->diffInYears($start->startOfDay()))) {
                $operation->reject('age_not_admitted', 422);

                return $operation;
            }

            try {
                $windows = $this->availability->search(new AvailabilityCriteria(
                    branchIds: [$branch->id], doctorIds: [$doctor->id], dateFrom: $start->toDateString(),
                    timeFrom: $start->format('H:i'), timeTo: $start->format('H:i'),
                ))['data'];
            } catch (ValidationException) {
                $windows = [];
            }
            $matches = collect($windows)->contains(fn (array $window) => $window['id'] === $offer['slot']
                && $window['starts_at'] === $offer['start'] && $window['ends_at'] === $offer['end']);
            if (! $matches || $this->hasOverlappingOperation($operation, $start, $end)
                || $this->local->hasApplicationOverlap($doctor->id, $cabinetId, $start, $end)) {
                $operation->reject('slot_unavailable', 409);

                return $operation;
            }

            $application = new Application([
                'city_id' => $branch->city_id, 'clinic_id' => $branch->clinic_id, 'branch_id' => $branch->id,
                'doctor_id' => $doctor->id, 'cabinet_id' => $cabinetId, 'appointment_datetime' => $start->utc(),
                'full_name' => $data['full_name'], 'full_name_parent' => $data['full_name_parent'],
                'birth_date' => $data['birth_date'], 'phone' => $data['phone'], 'send_to_1c' => false,
                'source' => Application::SOURCE_FRONTEND, 'submission_source' => $clientId,
                'submission_type' => Application::SUBMISSION_TYPE_APPOINTMENT_FORM,
                'integration_type' => Application::INTEGRATION_TYPE_LOCAL,
                'integration_payload' => array_intersect_key($data, ['comment' => true]),
            ]);
            $application->fillStatusFromSlug(Application::STATUS_SLUG_APPOINTMENT_CONFIRMED);
            $application->saveQuietly();
            $operation->update(['status' => 'confirmed', 'application_id' => $application->id]);
            DB::afterCommit(fn () => Application::clearCalendarCache());

            return $operation;
        }, 3);

        return $operation->wasRecentlyCreated ? $this->result($operation, created: true) : $this->replay($operation, $hash);
    }

    private function startOperation(
        string $clientId,
        string $key,
        string $hash,
        array $offer,
        ?int $cabinetId,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): BookingOperation {
        return BookingOperation::query()->createOrFirst(
            ['client_id' => $clientId, 'operation_key' => $key],
            [
                'request_hash' => $hash, 'onec_request_id' => (string) Str::uuid(),
                'branch_id' => $offer['branch'], 'doctor_id' => $offer['doctor'], 'cabinet_id' => $cabinetId,
                'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'status' => 'unknown',
            ],
        );
    }

    public function status(string $clientId, ?array $allowedBranchIds, string $key): array
    {
        $operation = $this->operation($clientId, $key);
        abort_unless($operation && ($allowedBranchIds === null || in_array((int) $operation->branch_id, $allowedBranchIds, true)), 404);

        return $this->result($operation, reading: true);
    }

    private function operation(string $clientId, string $key): ?BookingOperation
    {
        return BookingOperation::query()->where('client_id', $clientId)->where('operation_key', $key)->first();
    }

    private function replay(BookingOperation $operation, string $hash): array
    {
        if (! hash_equals($operation->request_hash, $hash)) {
            return [['code' => 'idempotency_conflict'], 409];
        }

        return $this->result($operation);
    }

    private function result(BookingOperation $operation, bool $created = false, bool $reading = false): array
    {
        $data = ['operation_id' => $operation->operation_key, 'status' => $operation->status];
        if ($operation->status === 'confirmed') {
            return [$data + ['booking_id' => (string) $operation->application_id], $created ? 201 : 200];
        }
        if ($operation->status === 'rejected') {
            return [$data + ['code' => $operation->result_code], $reading ? 200 : $operation->result_http_status];
        }

        return [$data, 202];
    }

    private function checkWindow(
        BookingOperation $operation,
        array $offer,
        OnecSlot $slot,
        Doctor $doctor,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $birthDate,
    ): ?array {
        $branch = $slot->branch;
        $endpoint = $branch->integrationEndpoint;
        if (! $branch->isOnecPushMode() || ! $endpoint?->is_active || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC
            || (! $endpoint->base_url && empty($endpoint->credentials['base_url']))
            || ! $doctor->external_id || ! $slot->external_slot_id) {
            return ['source_unavailable', 503];
        }
        if (! $slot->isFree() || ! $this->offers->matchesWindow($offer, $slot, $branch) || $start <= now()) {
            return ['slot_unavailable', 409];
        }

        $birth = CarbonImmutable::parse($birthDate, $start->getTimezone());
        if (! $doctor->admitsAge((int) $birth->diffInYears($start->startOfDay()))) {
            return ['age_not_admitted', 422];
        }

        try {
            $search = $this->availability->search(new AvailabilityCriteria(
                branchIds: [$branch->id], doctorIds: [$doctor->id], dateFrom: $start->toDateString(),
                timeFrom: $start->format('H:i'), timeTo: $start->format('H:i'),
            ));
        } catch (ValidationException) {
            return ['slot_unavailable', 409];
        }

        if ($search['meta']['schedule_status'] !== 'complete') {
            return ['schedule_unavailable', 503];
        }
        if (! in_array($offer['slot'], array_column($search['data'], 'id'), true) || $this->hasOverlap($operation, $start, $end)) {
            return ['slot_unavailable', 409];
        }

        return null;
    }

    private function hasOverlap(BookingOperation $operation, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($this->hasOverlappingOperation($operation, $start, $end)) {
            return true;
        }

        $timezone = config('app.timezone');
        if (OnecSlot::query()->where('status', '!=', OnecSlot::STATUS_FREE)
            ->where('start_at', '<', $end->setTimezone($timezone))
            ->where('end_at', '>', $start->setTimezone($timezone))->where($this->sameResource($operation))->exists()) {
            return true;
        }

        return $this->local->hasApplicationOverlap($operation->doctor_id, $operation->cabinet_id, $start, $end);
    }

    private function hasOverlappingOperation(BookingOperation $operation, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return BookingOperation::query()->whereKeyNot($operation->id)
            ->where(fn (Builder $query) => $query->where('status', 'unknown')
                ->orWhere(fn (Builder $query) => $query->where('status', 'confirmed')
                    ->whereIn('application_id', Application::query()->select('id'))))
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())
            ->where($this->sameResource($operation))->exists();
    }

    private function sameResource(BookingOperation $operation): \Closure
    {
        return fn (Builder $query) => $query->where('doctor_id', $operation->doctor_id)
            ->when($operation->cabinet_id, fn (Builder $query, $id) => $query->orWhere('cabinet_id', $id));
    }
}
