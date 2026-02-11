<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Contracts;

use App\Services\OneC\Exceptions\OneCBookingException;

/**
 * Контракт, который преобразует исключение отмены записи в 1С в понятный ответ.
 * Используется UI/API, чтобы предложить администратору удалить заявку локально.
 */
interface CancellationConflictResolver
{
    /**
     * Возвращает массив с информацией о конфликте или null, если ошибка не распознана.
     *
     * Пример ответа:
     * [
     *     'code' => 'onec_already_deleted',
     *     'message' => 'Запись уже удалена в 1С.',
     *     'can_force_delete' => true,
     * ]
     */
    public function buildConflictPayload(OneCBookingException $exception): ?array;
}
