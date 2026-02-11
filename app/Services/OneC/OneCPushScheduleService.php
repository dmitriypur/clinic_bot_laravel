<?php

declare(strict_types=1);

namespace App\Services\OneC;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\IntegrationEndpoint;
use App\Services\OneC\Exceptions\OneCException;

class OneCPushScheduleService
{
    public function __construct(private readonly OneCSlotSyncService $slotSyncService) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $slots
     * @return array<string, int>
     *
     * @throws OneCException
     */
    /**
     * Принимает батч слотов из вебхука и прокидывает его в сервис синхронизации.
     * Возвращаем статистику (создано/обновлено/заблокировано), чтобы вебхук понимал результат.
     */
    public function import(Clinic $clinic, Branch $branch, array $slots): array
    {
        $endpoint = $branch->integrationEndpoint;

        if (! $endpoint || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC || ! $endpoint->is_active) {
            throw new OneCException('Интеграция с 1С для филиала не настроена или отключена.', [
                'branch_id' => $branch->id,
            ]);
        }

        // Само применение батча делает специализированный OneCSlotSyncService.
        return $this->slotSyncService->upsertSlotsFromPayloads($clinic, $branch, $endpoint, $slots);
    }
}
