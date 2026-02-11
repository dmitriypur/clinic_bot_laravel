<?php

declare(strict_types=1);

namespace App\Modules\OnecSync\Services;

use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Services\OneC\Exceptions\OneCBookingException;
use Illuminate\Support\Arr;

/**
 * Анализирует исключения отмены брони в 1С и пытается понять,
 * не говорит ли она, что запись уже отсутствует у них.
 */
class CancellationConflictResolverService implements CancellationConflictResolver
{
    public function buildConflictPayload(OneCBookingException $exception): ?array
    {
        // В контекст OneCBookingException мы прокидываем данные API-ответа (статус, body).
        $context = $exception->context();
        $apiErrorContext = Arr::get($context, 'api_error', []);

        $status = (int) Arr::get($apiErrorContext, 'status', 0);
        $body = Arr::get($apiErrorContext, 'body');

        // Пробуем вытащить человекочитаемое сообщение, чтобы показать его в UI.
        $detail = null;

        if (is_array($body)) {
            $detail = (string) ($body['detail'] ?? $body['message'] ?? '');
        } elseif (is_string($body)) {
            $detail = $body;
        }

        $detail = trim((string) $detail);

        // Конфликт распознаётся по HTTP 404/410 или тексту «not found».
        $isNotFound = in_array($status, [404, 410], true)
            || ($detail !== '' && str_contains(mb_strtolower($detail), 'not found'));

        if (! $isNotFound) {
            return null;
        }

        return [
            'code' => 'onec_already_deleted',
            'message' => $detail !== '' ? $detail : 'Запись уже удалена в 1С.',
            'can_force_delete' => true,
        ];
    }
}
