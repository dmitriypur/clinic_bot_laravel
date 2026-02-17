<?php

namespace App\Services\OneC;

use App\Models\Application;
use App\Models\Branch;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use App\Services\OneC\Exceptions\OneCApiException;
use App\Services\OneC\Exceptions\OneCBookingException;
use App\Services\OneC\Exceptions\OneCException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OneCBookingService
{
    public function __construct(private readonly OneCApiClient $apiClient) {}

    /**
     * Бронирование слота через 1С.
     *
     * @throws OneCBookingException
     */
    public function book(Application $application, OnecSlot $slot, array $extraPayload = []): array
    {
        // Берём филиал либо из слота, либо из самой заявки — без него нельзя выбрать нужный endpoint.
        $branch = $slot->branch ?? $application->branch;

        if (! $branch) {
            throw new OneCBookingException('Для заявки не определён филиал, бронирование через 1С невозможно.', [
                'application_id' => $application->id,
            ]);
        }

        $endpoint = $this->validateEndpoint($branch);

        // Врач обязателен: в 1С запись всегда привязана к внешнему ID доктора.
        $doctor = $slot->doctor ?? $application->doctor;

        if (! $doctor || ! $doctor->external_id) {
            throw new OneCBookingException('Для врача в 1С отсутствует внешний идентификатор.', [
                'application_id' => $application->id,
                'slot_id' => $slot->id,
            ]);
        }

        if (! $slot->start_at) {
            throw new OneCBookingException('У слота отсутствует дата/время начала.', [
                'slot_id' => $slot->id,
            ]);
        }

        // Используем существующий appointment_id, чтобы 1С не создавала клонов,
        // либо генерим новый UUID, когда бронь создаётся впервые.
        $appointmentId = (string) ($extraPayload['appointment_id']
            ?? $application->external_appointment_id
            ?? Str::uuid());

        $extraPayload['appointment_id'] = $appointmentId;

        $payload = $this->buildBookingPayload(
            $application,
            $slot,
            $branch,
            array_merge($extraPayload, [
                'appointment_id' => $appointmentId,
            ])
        );

        try {
            Log::info('OneC booking request', [
                'endpoint_id' => $endpoint->id,
                'payload' => $payload,
            ]);

            $response = $this->apiClient->bookSlot($endpoint, $payload);

            Log::info('OneC booking response', [
                'endpoint_id' => $endpoint->id,
                'response' => is_array($response) ? $response : ($response->json() ?? $response->body()),
            ]);
        } catch (OneCApiException $exception) {
            $this->markEndpointFailed($endpoint, $exception);

            $message = $this->extractApiErrorMessage($exception) ?: 'Ошибка бронирования в 1С.';

            throw new OneCBookingException($message, [
                'application_id' => $application->id,
                'branch_id' => $branch->id,
                'payload' => $payload,
                'api_error' => $exception->context(),
            ], 0, $exception);
        }

        $this->markEndpointSuccess($endpoint);

        $statusCode = (int) Arr::get($response, 'status_code', 200);
        $status = strtolower((string) Arr::get($response, 'status', 'success'));

        if ($statusCode >= 400 || $status === 'fail') {
            $message = Arr::get($response, 'detail')
                ?? Arr::get($response, 'message')
                ?? '1С отклонила запись.';

            throw new OneCBookingException($message, [
                'application_id' => $application->id,
                'branch_id' => $branch->id,
                'status_code' => $statusCode,
                'payload' => $payload,
                'response' => $response,
            ]);
        }

        $externalAppointmentId = (string) Arr::get($response, 'claim_id', Arr::get($response, 'appointment_id', $appointmentId));
        $integrationStatus = Arr::get($response, 'status', 'booked');

        // Фиксируем успешную бронь сразу в заявке и слоте, чтобы календарь стал «занятым».
        $application->forceFill([
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
            'integration_status' => $integrationStatus,
            'external_appointment_id' => $externalAppointmentId,
            'integration_payload' => $response,
            'branch_id' => $branch->id,
        ]);

        if ($slot->start_at) {
            $application->appointment_datetime = $slot->start_at;
        }

        $application->save();

        $slot->forceFill([
            'status' => OnecSlot::STATUS_BOOKED,
            'booking_uuid' => $externalAppointmentId,
            'synced_at' => now(),
        ])->save();

        return $response;
    }

    /**
     * Отмена брони в 1С.
     *
     * @throws OneCBookingException
     */
    public function cancel(Application $application, array $extraPayload = []): array
    {
        $branch = $application->branch;

        if (! $branch) {
            throw new OneCBookingException('У заявки отсутствует филиал, отмена через 1С невозможна.', [
                'application_id' => $application->id,
            ]);
        }

        if (! $application->external_appointment_id) {
            throw new OneCBookingException('У заявки отсутствует внешнее бронирование, отменять нечего.', [
                'application_id' => $application->id,
            ]);
        }

        $endpoint = $this->validateEndpoint($branch);

        try {
            $response = $this->apiClient->cancelBooking($endpoint, $application->external_appointment_id, $extraPayload);
        } catch (OneCApiException $exception) {
            $this->markEndpointFailed($endpoint, $exception);

            // Прокидываем оригинальный ответ 1С, чтобы модуль синхронизации мог понять причину отказа.
            throw new OneCBookingException('Ошибка при отмене записи в 1С.', [
                'application_id' => $application->id,
                'branch_id' => $branch->id,
                'api_error' => $exception->context(),
            ], 0, $exception);
        }

        $this->markEndpointSuccess($endpoint);

        $status = (string) Arr::get($response, 'status', 'cancelled');

        $application->forceFill([
            'integration_status' => $status,
            'integration_payload' => $response,
        ])->save();

        // Если нашли слот, связанный с этой бронью, освобождаем его, чтобы вернулся в выдачу пользователям.
        if ($slot = $this->findSlotByExternalId($branch, $application->external_appointment_id)) {
            $slot->forceFill([
                'status' => OnecSlot::STATUS_FREE,
                'booking_uuid' => null,
                'synced_at' => now(),
            ])->save();
        }

        return $response;
    }

    protected function buildBookingPayload(Application $application, OnecSlot $slot, Branch $branch, array $extraPayload = []): array
    {
        $comment = (string) Arr::get($extraPayload, 'comment', '');
        if ($application->full_name_parent) {
            $parentInfo = "Родитель: {$application->full_name_parent}";
            $comment = $comment ? "{$comment}. {$parentInfo}" : $parentInfo;
        }

        $appointmentSource = (string) Arr::get($extraPayload, 'appointment_source', 'Приложение');

        return array_merge([
            'appointment_id' => Arr::get($extraPayload, 'appointment_id'),
            'slot_id' => $slot->external_slot_id,
            'clinic_external_id' => $branch->clinic?->external_id,
            'doctor_external_id' => $slot->doctor?->external_id ?? $application->doctor?->external_id,
            'branch_external_id' => $branch->external_id,
            'cabinet_external_id' => $slot->cabinet?->external_id,
            'comment' => $comment,
            'appointment_source' => $appointmentSource,
            'patient' => [
                'full_name' => $application->full_name,
                'birth_date' => $application->birth_date,
                'phone' => $application->phone,
                'full_name_parent' => $application->full_name_parent,
            ],
            'meta' => [
                'application_id' => $application->id,
                'source' => $application->source,
            ],
        ], $extraPayload);
    }

    protected function validateEndpoint(Branch $branch): IntegrationEndpoint
    {
        $endpoint = $branch->integrationEndpoint;

        if (! $endpoint || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC) {
            throw new OneCBookingException('Для филиала не настроена интеграция с 1С.', [
                'branch_id' => $branch->id,
            ]);
        }

        if (! $endpoint->is_active) {
            throw new OneCBookingException('Интеграция с 1С отключена.', [
                'branch_id' => $branch->id,
            ]);
        }

        if (! $endpoint->base_url && empty($endpoint->credentials['base_url'])) {
            throw new OneCBookingException('В настройках интеграции не указан адрес API 1С.', [
                'branch_id' => $branch->id,
            ]);
        }

        return $endpoint;
    }

    protected function markEndpointFailed(IntegrationEndpoint $endpoint, OneCException $exception): void
    {
        $endpoint->forceFill([
            'last_error_at' => now(),
            'last_error_message' => $exception->getMessage(),
        ])->saveQuietly();
    }

    protected function markEndpointSuccess(IntegrationEndpoint $endpoint): void
    {
        $endpoint->forceFill([
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ])->saveQuietly();
    }

    protected function findSlotByExternalId(Branch $branch, string $externalAppointmentId): ?OnecSlot
    {
        return OnecSlot::query()
            ->where('clinic_id', $branch->clinic_id)
            ->where('branch_id', $branch->id)
            ->where(function ($query) use ($externalAppointmentId) {
                $query->where('external_slot_id', $externalAppointmentId)
                    ->orWhere('booking_uuid', $externalAppointmentId)
                    ->orWhere('source_payload->appointment_id', $externalAppointmentId);
            })
            ->first();
    }

    /**
     * Создание записи напрямую в 1С без выбора слота.
     *
     * @throws OneCBookingException
     */
    public function bookDirect(Application $application, Branch $branch, array $extraPayload = []): array
    {
        // Ручная запись возможна только если пользователь указал конкретную дату/время.
        if (! $application->appointment_datetime) {
            throw new OneCBookingException('Не указана дата/время приема для записи в 1С.', [
                'application_id' => $application->id,
            ]);
        }

        $doctor = $application->relationLoaded('doctor')
            ? $application->doctor
            : $application->doctor()->first();

        if (! $doctor || ! $doctor->external_id) {
            throw new OneCBookingException('Для врача не указан внешний идентификатор 1С.', [
                'application_id' => $application->id,
                'doctor_id' => $application->doctor_id,
            ]);
        }

        $endpoint = $this->validateEndpoint($branch);

        // Переносим существующую бронь, если уже есть external_appointment_id.
        $appointmentId = (string) ($extraPayload['appointment_id']
            ?? $application->external_appointment_id
            ?? Str::uuid());

        $extraPayload['appointment_id'] = $appointmentId;

        // Строим payload в «ручном» формате (без slot_id), 1С сама подберёт окно.
        $payload = $this->buildDirectBookingPayload($application, $doctor->external_id, $extraPayload);

        try {
            Log::info('OneC booking request', [
                'endpoint_id' => $endpoint->id,
                'payload' => $payload,
            ]);

            $response = $this->apiClient->createManualBooking($endpoint, $payload);

            Log::info('OneC booking response', [
                'endpoint_id' => $endpoint->id,
                'response' => is_array($response) ? $response : ($response->json() ?? $response->body()),
            ]);
        } catch (OneCApiException $exception) {
            $this->markEndpointFailed($endpoint, $exception);

            $message = $this->extractApiErrorMessage($exception) ?: 'Ошибка при создании записи в 1С.';

            throw new OneCBookingException($message, [
                'application_id' => $application->id,
                'branch_id' => $branch->id,
                'payload' => $payload,
                'api_error' => $exception->context(),
            ], 0, $exception);
        }

        $statusCode = (int) Arr::get($response, 'status_code', 200);
        $status = strtolower((string) Arr::get($response, 'status', 'success'));

        if ($statusCode >= 400 || $status === 'fail') {
            $message = Arr::get($response, 'detail')
                ?? Arr::get($response, 'message')
                ?? '1С отклонила запись.';

            throw new OneCBookingException($message, [
                'application_id' => $application->id,
                'branch_id' => $branch->id,
                'status_code' => $statusCode,
                'payload' => $payload,
                'response' => $response,
            ]);
        }

        $externalAppointmentId = (string) Arr::get($response, 'claim_id', Arr::get($response, 'appointment_id', ''));

        if ($externalAppointmentId === '') {
            throw new OneCBookingException('1С не вернула идентификатор созданной записи.', [
                'application_id' => $application->id,
                'response' => $response,
            ]);
        }

        $application->forceFill([
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
            'integration_status' => 'booked',
            'external_appointment_id' => $externalAppointmentId,
            'integration_payload' => $response,
            'branch_id' => $branch->id,
            'clinic_id' => $application->clinic_id ?? $branch->clinic_id,
        ])->save();

        Log::info('OneC manual booking response', [
            'application_id' => $application->id,
            'branch_id' => $branch->id,
            'payload' => $payload,
            'response' => $response,
        ]);

        $this->markEndpointSuccess($endpoint);

        return $response;
    }

    protected function buildDirectBookingPayload(Application $application, string $doctorExternalId, array $extraPayload = []): array
    {
        $timezone = config('app.timezone', 'UTC');

        if ($application->appointment_datetime instanceof Carbon) {
            $dtStart = $application->appointment_datetime->copy()->setTimezone($timezone);
        } else {
            $dtStart = Carbon::parse((string) $application->appointment_datetime, $timezone);
        }

        return $this->buildManualBookingPayload($application, $doctorExternalId, $dtStart, $extraPayload);
    }

    protected function buildManualBookingPayload(Application $application, string $doctorExternalId, Carbon $dtStart, array $extraPayload = []): array
    {
        [$lastName, $firstName, $secondName] = $this->splitFullName($application->full_name);

        $comment = (string) Arr::get($extraPayload, 'comment', '');
        if ($application->full_name_parent) {
            $parentInfo = "Родитель: {$application->full_name_parent}";
            $comment = $comment ? "{$comment}. {$parentInfo}" : $parentInfo;
        }
        $appointmentSource = (string) Arr::get($extraPayload, 'appointment_source', 'Приложение');

        return [
            'appointment_id' => Arr::get($extraPayload, 'appointment_id'),
            'doctor' => [
                'id' => $doctorExternalId,
            ],
            'appointment' => [
                'dt_start' => $dtStart->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            'client' => [
                'mobile_phone' => $this->normalizePhone($application->phone),
                'last_name' => $lastName,
                'first_name' => $firstName,
                'second_name' => $secondName,
                'birthday' => $this->normalizeBirthDate($application->birth_date),
            ],
            'appointment_source' => $appointmentSource,
        ];
    }

    protected function splitFullName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return ['Пациент', '1С', null];
        }

        $parts = preg_split('/\s+/u', $fullName, 3);

        $lastName = $parts[0] ?? 'Пациент';
        $firstName = $parts[1] ?? '';
        $secondName = $parts[2] ?? '';

        return [$lastName, $firstName, $secondName];
    }

    protected function normalizePhone(?string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $phone);

        if ($normalized === '') {
            return '0000000000';
        }

        return $normalized;
    }

    protected function normalizeBirthDate(?string $birthDate): ?string
    {
        $birthDate = (string) $birthDate;
        if ($birthDate === '') {
            return null;
        }
        try {
            return Carbon::parse($birthDate)->format('Y-m-d');
        } catch (\Throwable) {
            return substr($birthDate, 0, 15);
        }
    }

    protected function extractApiErrorMessage(OneCException $exception): string
    {
        $ctx = $exception->context();
        $body = $ctx['body'] ?? null;

        if (is_array($body)) {
            return (string) ($body['detail'] ?? $body['message'] ?? $exception->getMessage());
        }

        if (is_string($body) && $body !== '') {
            return $body;
        }

        return $exception->getMessage();
    }
}
