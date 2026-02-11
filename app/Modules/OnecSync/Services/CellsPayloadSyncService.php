<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Services;

use App\Models\Application;
use App\Models\Clinic;
use App\Models\OnecSlot;
use App\Modules\OnecSync\Contracts\CellsPayloadSyncFeature;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Основная реализация обработчика ячеек расписания.
 * Отвечает за привязку claim_id к локальной заявке и синхронизацию её статусов.
 */
class CellsPayloadSyncService implements CellsPayloadSyncFeature
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly bool $enabled,
        private readonly bool $autoDeleteOnFree,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Сопоставляет claim_id из ячейки с нашей заявкой и аккуратно обновляет её.
     */
    public function handleCellPayload(Clinic $clinic, OnecSlot $slot, array $cell, string $status): void
    {
        if (! $this->enabled) {
            return;
        }

        // claim_id — единственный ключ, по которому можно понять, что слот наш.
        $claimId = trim((string) Arr::get($cell, 'claim_id', ''));

        if ($claimId === '') {
            return;
        }

        /** @var Application|null $application */
        $application = Application::query()
            ->where('external_appointment_id', $claimId)
            ->first();

        if (! $application) {
            return;
        }

        // Нужно ли удалять заявку: и статус слота, и флаг free должны говорить, что запись снята.
        $shouldDelete = $this->autoDeleteOnFree && (
            $status === OnecSlot::STATUS_FREE
            || (bool) Arr::get($cell, 'free', false)
        );

        $this->db->transaction(function () use ($application, $slot, $cell, $status, $shouldDelete, $clinic) {
            // Обновляем интеграционные поля, чтобы история соответствовала 1С.
            $application->forceFill([
                'integration_type' => Application::INTEGRATION_TYPE_ONEC,
                'integration_status' => $status,
                'integration_payload' => array_merge(
                    $application->integration_payload ?? [],
                    ['cells_payload' => $cell]
                ),
                'clinic_id' => $application->clinic_id ?? $clinic->id,
            ]);

            // Слот может содержать уточнённое время/филиал/врача — фиксируем их.
            if ($slot->start_at) {
                $application->appointment_datetime = $slot->start_at;
            }

            if ($slot->branch_id && ! $application->branch_id) {
                $application->branch_id = $slot->branch_id;
            }

            if ($slot->doctor_id && ! $application->doctor_id) {
                $application->doctor_id = $slot->doctor_id;
            }

            $application->save();

            if ($shouldDelete) {
                $application->delete();

                Log::info('OneCSync: локальная заявка удалена после отмены в 1С.', [
                    'application_id' => $application->id,
                    'claim_id' => $application->external_appointment_id,
                ]);
            }
        });
    }
}
