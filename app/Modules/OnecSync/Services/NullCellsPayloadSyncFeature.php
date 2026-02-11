<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Services;

use App\Models\Clinic;
use App\Models\OnecSlot;
use App\Modules\OnecSync\Contracts\CellsPayloadSyncFeature;

/**
 * Пустая реализация обработчика ячеек. Используем, когда модуль отключён,
 * чтобы основная логика не проверяла флаги повсюду.
 */
class NullCellsPayloadSyncFeature implements CellsPayloadSyncFeature
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function handleCellPayload(Clinic $clinic, OnecSlot $slot, array $cell, string $status): void
    {
        // Ничего не делаем — модуль выключен.
    }
}
