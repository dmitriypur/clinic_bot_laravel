<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Contracts;

use App\Models\Clinic;
use App\Models\OnecSlot;

/**
 * Контракт обработчика payload `cells[]`, который 1С отправляет батчами.
 * Реализация должна уметь узнавать наши заявки по `claim_id` и синхронизировать их.
 */
interface CellsPayloadSyncFeature
{
    /**
     * Возвращает true, если модуль сейчас активен и должен выполнять логику.
     */
    public function isEnabled(): bool;

    /**
     * Сопоставляет ячейку 1С с локальной заявкой и обновляет её состояние.
     *
     * @param  Clinic   $clinic Клиника, на которую пришёл webhook.
     * @param  OnecSlot $slot   Актуальная запись слота после upsert-а.
     * @param  array    $cell   Исходные данные ячейки из webhook (включая claim_id).
     * @param  string   $status Статус, в который переведён слот (booked/free).
     */
    public function handleCellPayload(Clinic $clinic, OnecSlot $slot, array $cell, string $status): void;
}
