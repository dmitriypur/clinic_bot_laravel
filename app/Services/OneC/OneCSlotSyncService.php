<?php

namespace App\Services\OneC;

use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ExternalMapping;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OneCSlotSyncService
{
    /**
     * Кэш соответствий external_id -> local_id для ускорения работы.
     *
     * @var array<string, int|null>
     */
    protected array $resolvedMappings = [];

    /**
     * Применяет переданные payload'ы слотов к БД (используется pull и push сценариями).
     *
     * @return array<string, int>
     */
    public function upsertSlotsFromPayloads(Clinic $clinic, Branch $branch, IntegrationEndpoint $endpoint, array $payloads): array
    {
        $this->resolvedMappings = [];

        return DB::transaction(function () use ($clinic, $branch, $endpoint, $payloads) {
            $existing = $this->loadExistingSlots($clinic, $branch);
            $processedExternalIds = [];

            $created = 0;
            $updated = 0;

            // Каждая запись описывает слот: врач, кабинет, время, статус.
            foreach ($payloads as $payload) {
                $externalSlotId = (string) Arr::get($payload, 'id', Arr::get($payload, 'slot_id'));

                if ($externalSlotId === '') {
                    Log::warning('Пропускаем слот 1С без идентификатора.', [
                        'branch_id' => $branch->id,
                        'payload' => $payload,
                    ]);

                    continue;
                }

                $processedExternalIds[] = $externalSlotId;

                // Хэш помогает понять, менялся ли слот, даже если ID тот же.
                $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

                $bookingUuid = (string) Arr::get($payload, 'appointment_id', '');

                $slotData = [
                    'clinic_id' => $clinic->id,
                    'branch_id' => $branch->id,
                    'doctor_id' => $this->resolveLocalId($clinic, 'doctor', Arr::get($payload, 'doctor.external_id', Arr::get($payload, 'doctor_id')), $payload, $branch),
                    'cabinet_id' => $this->resolveLocalId($clinic, 'cabinet', Arr::get($payload, 'cabinet.external_id', Arr::get($payload, 'cabinet_id'))),
                    'start_at' => $this->parseDate(Arr::get($payload, 'start_at')),
                    'end_at' => $this->parseDate(Arr::get($payload, 'end_at')),
                    'status' => (string) Arr::get($payload, 'status', OnecSlot::STATUS_FREE),
                    'booking_uuid' => $bookingUuid !== '' ? $bookingUuid : null,
                    'payload_hash' => $payloadHash,
                    'source_payload' => $payload,
                    'synced_at' => now(),
                ];

                /** @var OnecSlot|null $existingSlot */
                $existingSlot = $existing[$externalSlotId] ?? null;

                if ($existingSlot && $existingSlot->payload_hash === $payloadHash) {
                    $hasSameDoctor = (int) ($existingSlot->doctor_id ?? 0) === (int) ($slotData['doctor_id'] ?? 0);
                    $hasSameCabinet = (int) ($existingSlot->cabinet_id ?? 0) === (int) ($slotData['cabinet_id'] ?? 0);
                    $hasSameBooking = (string) ($existingSlot->booking_uuid ?? '') === (string) ($slotData['booking_uuid'] ?? '');

                    if ($hasSameDoctor && $hasSameCabinet && $hasSameBooking) {
                        // Изменений нет — просто обновляем отметку `synced_at` и идём дальше.
                        $existingSlot->updateQuietly([
                            'synced_at' => now(),
                        ]);

                        continue;
                    }
                }

                $dbSlot = OnecSlot::query()
                    ->where('clinic_id', $clinic->id)
                    ->where('external_slot_id', $externalSlotId)
                    ->orderByDesc('id')
                    ->first();

                if ($dbSlot) {
                    $dbSlot->fill($slotData + [
                        'external_slot_id' => $externalSlotId,
                        'branch_id' => $branch->id,
                    ]);

                    if ($dbSlot->isDirty()) {
                        $dbSlot->save();
                        $updated++;
                    } else {
                        $dbSlot->touch();
                    }

                    $existing[$externalSlotId] = $dbSlot;
                } else {
                    OnecSlot::create(array_merge($slotData, [
                        'external_slot_id' => $externalSlotId,
                        'branch_id' => $branch->id,
                    ]));

                    $created++;
                }
            }

            // Всё, что не пришло в батче, считаем заблокированным (1С перестала их отдавать).
            $blocked = $this->markMissingSlotsAsBlocked($clinic, $branch, $processedExternalIds);

            $this->markEndpointSuccess($endpoint);

            return [
                'total_received' => count($payloads),
                'created' => $created,
                'updated' => $updated,
                'blocked' => $blocked,
            ];
        });
    }

    protected function loadExistingSlots(Clinic $clinic, Branch $branch): array
    {
        return OnecSlot::query()
            ->where('clinic_id', $clinic->id)
            ->where(function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)
                    ->orWhereNull('branch_id');
            })
            ->orderByDesc('id')
            ->get()
            ->keyBy('external_slot_id')
            ->all();
    }

    /**
     * Если слот отсутствует в свежем батче — 1С считает его занятым/недоступным.
     * Помечаем такие записи как blocked, чтобы фронт не предлагал их пациентам.
     */
    protected function markMissingSlotsAsBlocked(Clinic $clinic, Branch $branch, array $processedExternalIds): int
    {
        if (empty($processedExternalIds)) {
            return 0;
        }

        return OnecSlot::query()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereNotIn('external_slot_id', $processedExternalIds)
            ->update([
                'status' => OnecSlot::STATUS_BLOCKED,
                'booking_uuid' => null,
                'synced_at' => now(),
            ]);
    }

    protected function resolveLocalId(Clinic $clinic, string $type, mixed $externalId, array $payloadContext = [], ?Branch $branch = null): ?int
    {
        if (! $externalId) {
            return null;
        }

        $cacheKey = implode(':', [$clinic->id, $type, (string) $externalId]);

        if (array_key_exists($cacheKey, $this->resolvedMappings)) {
            return $this->resolvedMappings[$cacheKey];
        }

        $mapping = ExternalMapping::query()
            ->where('clinic_id', $clinic->id)
            ->where('local_type', $type)
            ->where('external_id', (string) $externalId)
            ->first();

        if ($mapping) {
            return $this->resolvedMappings[$cacheKey] = (int) $mapping->local_id;
        }

        $modelClass = $this->detectModelClass($type);

        if ($modelClass === null) {
            return $this->resolvedMappings[$cacheKey] = null;
        }

        $model = $modelClass::query()
            ->where('external_id', (string) $externalId)
            ->first();

        // Если не нашли по ID, пробуем найти врача по ФИО (строгое совпадение)
        if (! $model && $type === 'doctor' && ! empty($payloadContext)) {
            $doctorName = Arr::get($payloadContext, 'doctor.efio') ?? Arr::get($payloadContext, 'doctor.name');

            if ($doctorName) {
                // Разбираем ФИО из 1С: "Иванов Иван Иванович", учитывая возможные лишние пробелы
                $parts = preg_split('/\s+/', trim((string) $doctorName));
                $lastName = $parts[0] ?? null;
                $firstName = $parts[1] ?? null;
                $secondName = $parts[2] ?? null;

                if ($lastName && $firstName) {
                    $query = Doctor::query()
                        ->where('last_name', $lastName)
                        ->where('first_name', $firstName);

                    if ($secondName) {
                        $query->where('second_name', $secondName);
                    } else {
                        // Если в 1С нет отчества, ищем врача без отчества (NULL или пустая строка)
                        $query->where(function ($q) {
                            $q->whereNull('second_name')
                              ->orWhere('second_name', '');
                        });
                    }

                    $model = $query->first();

                    if ($model) {
                        // Если нашли совпадение - сохраняем external_id для будущих синхронизаций
                        $model->updateQuietly([
                            'external_id' => (string) $externalId,
                        ]);
                    }
                }
            }
        }

        // Если модель найдена (по ID или по имени) и это врач - обновляем привязки
        if ($model && $type === 'doctor') {
            // Привязываем к клинике
            $clinic->doctors()->syncWithoutDetaching([$model->id]);

            // Привязываем к филиалу, если он передан
            if ($branch) {
                $branch->doctors()->syncWithoutDetaching([$model->id]);
            }
        }

        return $this->resolvedMappings[$cacheKey] = $model?->id;
    }

    protected function detectModelClass(string $type): ?string
    {
        return match ($type) {
            'doctor' => Doctor::class,
            'branch' => Branch::class,
            'cabinet' => Cabinet::class,
            default => null,
        };
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            $timezone = config('app.timezone', 'UTC');

            if ($value instanceof CarbonInterface) {
                return $value->copy()->setTimezone($timezone);
            }

            return Carbon::parse($value, $timezone)->setTimezone($timezone);
        } catch (\Throwable $exception) {
            Log::warning('Не удалось разобрать дату слота 1С.', [
                'value' => $value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function markEndpointSuccess(IntegrationEndpoint $endpoint): void
    {
        $endpoint->forceFill([
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ])->saveQuietly();
    }
}
