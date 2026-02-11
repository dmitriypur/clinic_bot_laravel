<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Services;

use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Services\OneC\Exceptions\OneCBookingException;

/**
 * Пустая реализация распознавания конфликтов. Возвращает null, чтобы код,
 * вызывающий резолвер, продолжил работать по стандартному сценарию.
 */
class NullCancellationConflictResolver implements CancellationConflictResolver
{
    public function buildConflictPayload(OneCBookingException $exception): ?array
    {
        return null;
    }
}
