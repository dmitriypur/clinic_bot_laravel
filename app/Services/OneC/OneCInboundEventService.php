<?php

namespace App\Services\OneC;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ExternalMapping;
use App\Models\OnecSlot;
use App\Modules\OnecSync\Contracts\CellsPayloadSyncFeature;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Центральная точка входа для всех входящих событий 1С.
 * Класс принимает как вебхуки расписания (cells), так и события бронирований.
 */
class OneCInboundEventService
{
    /**
     * @param  CellsPayloadSyncFeature  $cellsPayloadSyncFeature  Подключаемый модуль синхронизации claim_id.
     */
    public function __construct(private readonly CellsPayloadSyncFeature $cellsPayloadSyncFeature) {}

    /**
     * Обрабатывает событие из 1С.
     */
    public function handle(Clinic $clinic, array $payload): void
    {
        // Вебхук передаёт тип события (booking.created и т.д.) — приводим к нижнему регистру и обрабатываем.
        $event = strtolower((string) Arr::get($payload, 'event'));

        match ($event) {
            'booking_created', 'booking.created' => $this->handleBookingCreated($clinic, $payload),
            'booking_updated', 'booking.updated' => $this->handleBookingUpdated($clinic, $payload),
            'booking_cancelled', 'booking_canceled', 'booking.cancelled', 'booking.canceled' => $this->handleBookingCancelled($clinic, $payload),
            default => Log::info('Получено неизвестное событие от 1С.', [
                'clinic_id' => $clinic->id,
                'payload' => $payload,
            ]),
        };
    }

    /**
     * Поддержка payload, где передаётся список ячеек расписания для врача.
     * Каждая ячейка интерпретируется как отдельный слот.
     */
    public function handleCellsPayload(Clinic $clinic, Branch $branch, array $payload): int
    {
        $branchExternalId = (string) (Arr::get($payload, 'branch_external_id')
            ?? Arr::get($payload, 'filial_id')
            ?? $branch->external_id);

        $doctorExternalId = (string) Arr::get($payload, 'doctor_id');
        $date = (string) Arr::get($payload, 'date');
        $cells = Arr::get($payload, 'cells', []);

        if ($branchExternalId === '' || $doctorExternalId === '' || $date === '' || ! is_array($cells)) {
            throw new \InvalidArgumentException('Неверный формат payload: отсутствуют обязательные поля (branch/doctor/date/cells).');
        }

        $updated = 0;

        // Каждая ячейка — кусочек времени (start/end + статус). Разбираем их по очереди.
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $slotPayload = $this->buildSlotPayloadFromCell($branchExternalId, $doctorExternalId, $date, $cell, $branch);

            if (! $slotPayload) {
                continue;
            }

            $status = Arr::get($cell, 'free', false) ? OnecSlot::STATUS_FREE : OnecSlot::STATUS_BOOKED;

            $slot = $this->upsertSlotFromPayload($clinic, ['slot' => $slotPayload], $status);

            if ($slot && $this->cellsPayloadSyncFeature->isEnabled()) {
                // Передаём enriched payload (claim_id, дата и т.д.) в модуль синхронизации.
                $this->cellsPayloadSyncFeature->handleCellPayload(
                    $clinic,
                    $slot,
                    array_merge($cell, [
                        'doctor_id' => $doctorExternalId,
                        'branch_id' => $branchExternalId,
                        'date' => $date,
                    ]),
                    $status
                );
            }
            $updated++;
        }

        return $updated;
    }

    protected function handleBookingCreated(Clinic $clinic, array $payload): void
    {
        DB::transaction(function () use ($clinic, $payload) {
            $slot = $this->upsertSlotFromPayload($clinic, $payload, OnecSlot::STATUS_BOOKED);
            $this->syncApplicationFromPayload($clinic, $payload, $slot, 'booked');
        });
    }

    protected function handleBookingUpdated(Clinic $clinic, array $payload): void
    {
        DB::transaction(function () use ($clinic, $payload) {
            $slot = $this->upsertSlotFromPayload($clinic, $payload, Arr::get($payload, 'status', OnecSlot::STATUS_BOOKED));
            $this->syncApplicationFromPayload($clinic, $payload, $slot, Arr::get($payload, 'status', 'booked'));
        });
    }

    protected function handleBookingCancelled(Clinic $clinic, array $payload): void
    {
        DB::transaction(function () use ($clinic, $payload) {
            $slot = $this->upsertSlotFromPayload($clinic, $payload, OnecSlot::STATUS_FREE);
            $this->syncApplicationFromPayload($clinic, $payload, $slot, 'cancelled');
        });
    }

    protected function upsertSlotFromPayload(Clinic $clinic, array $payload, string $status): OnecSlot
    {
        $slotPayload = Arr::get($payload, 'slot', $payload);

        $externalSlotId = (string) Arr::get($slotPayload, 'slot_id', Arr::get($slotPayload, 'id'));

        $bookingUuid = (string) Arr::get(
            $slotPayload,
            'appointment_id',
            Arr::get($slotPayload, 'booking_id', Arr::get($slotPayload, 'claim_id', ''))
        );

        $slotData = [
            'clinic_id' => $clinic->id,
            'doctor_id' => $this->resolveLocalId($clinic, 'doctor', Arr::get($slotPayload, 'doctor_external_id', Arr::get($slotPayload, 'doctor_id'))),
            'branch_id' => $this->resolveLocalId($clinic, 'branch', Arr::get($slotPayload, 'branch_external_id', Arr::get($slotPayload, 'branch_id'))),
            'cabinet_id' => $this->resolveLocalId($clinic, 'cabinet', Arr::get($slotPayload, 'cabinet_external_id', Arr::get($slotPayload, 'cabinet_id'))),
            'start_at' => $this->parseDate(Arr::get($slotPayload, 'start_at')),
            'end_at' => $this->parseDate(Arr::get($slotPayload, 'end_at')),
            'status' => $status,
            'booking_uuid' => $bookingUuid !== '' ? $bookingUuid : null,
            'payload_hash' => hash('sha256', json_encode($slotPayload)),
            'source_payload' => $slotPayload,
            'synced_at' => now(),
        ];

        $isSyntheticId = $externalSlotId === '' || str_starts_with($externalSlotId, 'cell:');

        if ($isSyntheticId && $slotData['start_at'] && $slotData['doctor_id']) {
            // Некоторые вебхуки не передают реальный slot_id. В этом случае пытаемся найти запись по времени и врачу.
            $query = OnecSlot::query()
                ->where('clinic_id', $clinic->id)
                ->where('doctor_id', $slotData['doctor_id'])
                ->where('start_at', $slotData['start_at']);

            if ($slotData['branch_id']) {
                $query->where('branch_id', $slotData['branch_id']);
            }

            /** @var OnecSlot|null $existingSlot */
            $existingSlot = $query->orderByDesc('id')->first();

            if ($existingSlot) {
                $externalSlotId = $existingSlot->external_slot_id;
            }
        }

        if ($externalSlotId === '') {
            throw new \InvalidArgumentException('В событии 1С отсутствует идентификатор слота и не удалось подобрать существующий.');
        }

        return OnecSlot::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'external_slot_id' => $externalSlotId,
            ],
            $slotData
        );
    }

    protected function syncApplicationFromPayload(Clinic $clinic, array $payload, ?OnecSlot $slot, string $status): void
    {
        $externalAppointmentId = (string) Arr::get($payload, 'appointment_id', Arr::get($payload, 'id'));

        if ($externalAppointmentId === '') {
            $externalAppointmentId = $slot?->external_slot_id;
        }

        if ($externalAppointmentId === '') {
            Log::warning('Не удалось определить идентификатор брони из события 1С.', [
                'payload' => $payload,
            ]);

            return;
        }

        $application = Application::query()
            ->where('external_appointment_id', $externalAppointmentId)
            ->first();

        if (! $application && ($applicationId = Arr::get($payload, 'meta.application_id'))) {
            $application = Application::query()->find($applicationId);
        }

        if (! $application) {
            // Если такой заявки ещё нет у нас — создаём локальную копию, чтобы фронт и админка её увидели.
            $application = $this->createApplicationFromPayload($clinic, $payload, $slot, $externalAppointmentId);
        }

        if (! $application) {
            return;
        }

        $application->forceFill([
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
            'integration_status' => $status,
            'external_appointment_id' => $externalAppointmentId,
            'integration_payload' => $payload,
        ]);

        if ($slot && $slot->start_at) {
            $application->appointment_datetime = $slot->start_at;
        }

        if ($slot && $slot->branch_id && ! $application->branch_id) {
            $application->branch_id = $slot->branch_id;
        }

        if ($slot && $slot->doctor_id && ! $application->doctor_id) {
            $application->doctor_id = $slot->doctor_id;
        }

        if ($slot && $slot->cabinet_id && ! $application->cabinet_id) {
            $application->cabinet_id = $slot->cabinet_id;
        }

        $application->save();
    }

    protected function createApplicationFromPayload(Clinic $clinic, array $payload, ?OnecSlot $slot, string $externalAppointmentId): ?Application
    {
        $patient = Arr::get($payload, 'patient', []);

        $branchId = $slot?->branch_id;
        $cityId = null;

        if ($branchId) {
            $branch = Branch::query()->find($branchId);
            $cityId = $branch?->city_id;
        }

        if (! $cityId) {
            $cityId = $clinic->branches()->value('city_id');
        }

        if (! $cityId) {
            // Без города и клиники запись админка не покажет — логируем, чтобы заполнить маппинги.
            Log::warning('Не удалось определить город для создания заявки из события 1С.', [
                'clinic_id' => $clinic->id,
                'payload' => $payload,
            ]);

            return null;
        }

        $application = new Application;
        $application->fill([
            'city_id' => $cityId,
            'clinic_id' => $clinic->id,
            'branch_id' => $branchId,
            'doctor_id' => $slot?->doctor_id,
            'cabinet_id' => $slot?->cabinet_id,
            'full_name' => Arr::get($patient, 'full_name') ?? 'Пациент 1С',
            'full_name_parent' => Arr::get($patient, 'full_name_parent'),
            'birth_date' => Arr::get($patient, 'birth_date'),
            'phone' => Arr::get($patient, 'phone', ''),
            'send_to_1c' => true,
            'source' => 'onec_webhook',
            'integration_type' => Application::INTEGRATION_TYPE_ONEC,
            'integration_status' => Arr::get($payload, 'status', 'booked'),
            'external_appointment_id' => $externalAppointmentId,
            'integration_payload' => $payload,
        ]);

        if ($slot && $slot->start_at) {
            $application->appointment_datetime = $slot->start_at;
        }

        if (! $application->phone) {
            Log::warning('В событии 1С отсутствует телефон пациента. Заявка создана без телефона.', [
                'clinic_id' => $clinic->id,
                'payload' => $payload,
            ]);
        }

        $application->save();

        return $application;
    }

    protected function resolveLocalId(Clinic $clinic, string $type, mixed $externalId): ?int
    {
        if (! $externalId) {
            return null;
        }

        $mapping = ExternalMapping::query()
            ->where('clinic_id', $clinic->id)
            ->where('local_type', $type)
            ->where('external_id', (string) $externalId)
            ->first();

        if ($mapping) {
            return (int) $mapping->local_id;
        }

        $modelClass = match ($type) {
            'doctor' => Doctor::class,
            'branch' => Branch::class,
            'cabinet' => Cabinet::class,
            default => null,
        };

        if (! $modelClass) {
            return null;
        }

        $model = $modelClass::query()
            ->where('external_id', (string) $externalId)
            ->first();

        return $model?->id;
    }

    protected function buildSlotPayloadFromCell(string $branchExternalId, string $doctorExternalId, string $date, array $cell, Branch $branch): ?array
    {
        $timeStart = Arr::get($cell, 'time_start');
        $timeEnd = Arr::get($cell, 'time_end');

        if (! $timeStart) {
            Log::warning('Ячейка 1С не содержит time_start.', [
                'branch_external_id' => $branchExternalId,
                'doctor_external_id' => $doctorExternalId,
                'date' => $date,
                'cell' => $cell,
            ]);

            return null;
        }

        $startAt = $this->combineDateAndTime($date, (string) $timeStart);
        $endAt = $timeEnd ? $this->combineDateAndTime($date, (string) $timeEnd) : null;

        if (! $endAt && $startAt) {
            $endAt = $startAt->copy()->addMinutes($branch->getEffectiveSlotDuration());
        }

        if (! $startAt || ! $endAt) {
            Log::warning('Не удалось вычислить границы слота из ячейки 1С.', [
                'branch_external_id' => $branchExternalId,
                'doctor_external_id' => $doctorExternalId,
                'date' => $date,
                'cell' => $cell,
            ]);

            return null;
        }

        $slotId = Arr::get($cell, 'slot_id')
            ?? $this->makeSyntheticSlotId($branchExternalId, $doctorExternalId, $date, (string) $timeStart);

        return [
            'slot_id' => $slotId,
            'doctor_external_id' => $doctorExternalId,
            'branch_external_id' => $branchExternalId,
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
            'claim_id' => Arr::get($cell, 'claim_id'),
            'free' => Arr::get($cell, 'free', false),
        ];
    }

    protected function makeSyntheticSlotId(string $branchExternalId, string $doctorExternalId, string $date, string $timeStart): string
    {
        return sprintf(
            'cell:%s:%s:%s:%s',
            $branchExternalId,
            $doctorExternalId,
            $date,
            $timeStart
        );
    }

    protected function combineDateAndTime(string $date, string $time): ?CarbonInterface
    {
        if ($date === '' || $time === '') {
            return null;
        }

        try {
            return Carbon::parse(sprintf('%s %s', $date, $time));
        } catch (\Throwable $exception) {
            Log::warning('Не удалось распарсить дату/время ячейки 1С.', [
                'date' => $date,
                'time' => $time,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function parseDate(mixed $value): ?CarbonInterface
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $exception) {
            Log::warning('Не удалось распарсить дату из события 1С.', [
                'value' => $value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
