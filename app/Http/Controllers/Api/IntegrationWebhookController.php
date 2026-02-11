<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OneCScheduleWebhookRequest;
use App\Models\Branch;
use App\Models\Clinic;
use App\Services\OneC\OneCInboundEventService;
use App\Services\OneC\OneCLegacyScheduleTransformer;
use App\Services\OneC\OneCPushScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly OneCInboundEventService $inboundEventService,
        private readonly OneCPushScheduleService $scheduleService,
        private readonly OneCLegacyScheduleTransformer $legacyScheduleTransformer,
    ) {}

    /**
     * Вебхук событий 1С: бронирования, обновления, отмены.
     * Может приходить как обычное событие, так и массив ячеек (cells).
     */
    public function handleBookings(Request $request, Clinic $clinic): JsonResponse
    {
        $payload = $request->all();

        $branchExternalId = Arr::get($payload, 'branch_external_id')
            ?? Arr::get($payload, 'slot.branch_external_id')
            ?? Arr::get($payload, 'slot.branch_id')
            ?? Arr::get($payload, 'filial_id');

        $branch = $this->findBranchByExternalId($clinic, $branchExternalId);
        $endpoint = $branch?->integrationEndpoint;

        if (! $endpoint) {
            abort(Response::HTTP_NOT_FOUND, 'Интеграция для филиала не настроена.');
        }

        $this->ensureRequestAuthorized($request, $endpoint->credentials ?? []);

        if ($branch && $this->isCellBasedBookingPayload($payload)) {
            // Cells-пакеты обновляют сразу несколько ячеек расписания.
            $updatedSlots = $this->inboundEventService->handleCellsPayload($clinic, $branch, $payload);

            return response()->json([
                'status' => 'accepted',
                'updated_slots' => $updatedSlots,
            ]);
        }

        // Обычные события обрабатываем централизованным сервисом (create/update/cancel).
        $this->inboundEventService->handle($clinic, $payload);

        return response()->json([
            'status' => 'accepted',
        ]);
    }

    /**
     * Вебхук расписания: 1С присылает батч слотов для филиала/клиники.
     */
    public function handleSchedule(OneCScheduleWebhookRequest $request, Clinic $clinic): JsonResponse
    {
        if (! $clinic->isOnecPushMode()) {
            abort(Response::HTTP_CONFLICT, 'Клиника работает в локальном режиме расписания.');
        }

        if ($request->hasStructuredSlots()) {
            $stats = $this->processSlotsForBranch($clinic, $request, (string) $request->validated('branch_external_id'), $request->slots());

            return response()->json([
                'status' => 'accepted',
                'stats' => $stats,
            ]);
        }

        // Legacy payloads преобразуем к единому виду, чтобы дальше работал тот же импорт.
        $batches = $this->legacyScheduleTransformer->transform($request->legacySchedule());

        if (empty($batches)) {
            abort(Response::HTTP_BAD_REQUEST, 'Не удалось разобрать расписание 1С.');
        }

        $stats = [];

        foreach ($batches as $branchExternalId => $slots) {
            $stats[$branchExternalId] = $this->processSlotsForBranch($clinic, $request, (string) $branchExternalId, $slots);
        }

        return response()->json([
            'status' => 'accepted',
            'stats' => $stats,
        ]);
    }

    protected function processSlotsForBranch(Clinic $clinic, Request $request, string $branchExternalId, array $slots): array
    {
        $branch = $this->findBranchByExternalId($clinic, $branchExternalId);

        if (! $branch) {
            abort(Response::HTTP_NOT_FOUND, sprintf('Филиал %s не найден или не связан с клиникой.', $branchExternalId));
        }

        if (! $branch->isOnecPushMode()) {
            abort(Response::HTTP_CONFLICT, sprintf('Филиал %s не переведён в push-режим.', $branchExternalId));
        }

        $endpoint = $branch->integrationEndpoint;

        if (! $endpoint) {
            abort(Response::HTTP_NOT_FOUND, 'Интеграция для филиала не настроена.');
        }

        // Любой входящий вебхук подписан X-Integration-Token — проверяем перед обработкой.
        $this->ensureRequestAuthorized($request, $endpoint->credentials ?? []);

        return $this->scheduleService->import($clinic, $branch, $slots);
    }

    protected function ensureRequestAuthorized(Request $request, array $credentials): void
    {
        $secret = Arr::get($credentials, 'webhook_secret');

        if (! $secret) {
            return;
        }

        // 1С отправляет секрет в заголовке — простая защита от случайных вызовов.
        $token = $request->header('X-Integration-Token');

        if (! hash_equals($secret, (string) $token)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Недопустимая подпись вебхука.');
        }
    }

    protected function findBranchByExternalId(Clinic $clinic, ?string $externalId): ?Branch
    {
        if (! $externalId) {
            return null;
        }

        return Branch::query()
            ->where('clinic_id', $clinic->id)
            ->where('external_id', $externalId)
            ->first();
    }

    protected function isCellBasedBookingPayload(array $payload): bool
    {
        return is_array(Arr::get($payload, 'cells'))
            && Arr::get($payload, 'doctor_id')
            && Arr::get($payload, 'date');
    }
}
