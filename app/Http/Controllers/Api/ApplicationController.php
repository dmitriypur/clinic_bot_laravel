<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Services\OneC\Exceptions\OneCBookingException;
use App\Services\OneC\OneCBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * @param  OneCBookingService            $bookingService              Исходящие операции с 1С.
     * @param  CancellationConflictResolver  $cancellationConflictResolver Парсер конфликтов отмены.
     */
    public function __construct(
        private readonly OneCBookingService $bookingService,
        private readonly CancellationConflictResolver $cancellationConflictResolver,
    ) {}

    /**
     * Display a listing of applications.
     */
    public function index()
    {
        return response()->json([
            'error' => 'Page not found',
        ], 404);
        $applications = Application::with(['city', 'clinic', 'doctor'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return ApplicationResource::collection($applications);
    }

    /**
     * Store a newly created application.
     *
     * Здесь решается, нужно ли отправлять бронь в 1С:
     * 1. Валидируем данные и определяем филиал/клинику.
     * 2. Проверяем, в каком режиме работает филиал (push или классический).
     * 3. Внутри транзакции создаём заявку и вызываем нужный метод OneCBookingService.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'city_id' => 'required|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'branch_id' => 'nullable|exists:branches,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'cabinet_id' => 'nullable|exists:cabinets,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'nullable|string|max:30',
            'phone' => 'required|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'tg_user_id' => 'nullable|integer',
            'tg_chat_id' => 'nullable|integer',
            'send_to_1c' => 'boolean',
            'appointment_datetime' => 'nullable|date_format:Y-m-d H:i',
            'onec_slot_id' => 'nullable|string|max:191',
            'comment' => 'nullable|string|max:500',
            'appointment_source' => 'nullable|string|max:100',
        ]);

        // Генерируем ID как в Python версии (BigInteger), чтобы совместимость с внешними системами не ломалась.
        $validated['id'] = now()->format('YmdHis').rand(1000, 9999);

        // Автоматически помечаем источник — помогает потом фильтровать заявки в админке.
        $validated['source'] = Application::SOURCE_FRONTEND;

        $this->normalizeBirthDate($validated);

        [$branch, $clinic] = $this->resolveBranchAndClinic(
            $validated['branch_id'] ?? null,
            $validated['clinic_id'] ?? null
        );

        if ($branch && $clinic) {
            $validated['clinic_id'] = $clinic->id;
        }

        [$requiresOneC, $isPushMode] = $this->determineIntegrationContext($branch, $clinic);

        [$slotExternalId, $commentForOneC, $appointmentSource] = $this->extractIntegrationFields($validated);

        $manualOnecBooking = $this->shouldUseManualBooking($requiresOneC, $isPushMode, $slotExternalId);

        $this->validateBookingMode($requiresOneC, $manualOnecBooking, $slotExternalId, $validated);

        if (! isset($validated['integration_type'])) {
            $validated['integration_type'] = $requiresOneC
                ? Application::INTEGRATION_TYPE_ONEC
                : Application::INTEGRATION_TYPE_LOCAL;
        }

        $application = null;

        try {
            $application = Application::create($validated);

            if ($requiresOneC) {
                $this->bookOneCApplication(
                    application: $application,
                    clinic: $clinic,
                    branch: $branch,
                    slotExternalId: $slotExternalId,
                    manualBooking: $manualOnecBooking,
                    comment: $commentForOneC,
                    appointmentSource: $appointmentSource,
                    logContext: 'store'
                );

                $application->refresh();
            }
        } catch (OneCBookingException $exception) {
            $this->cleanupFailedApplicationCreation($application);
            report($exception);

            $errorField = $manualOnecBooking ? 'appointment_datetime' : 'onec_slot_id';

            throw ValidationException::withMessages([
                $errorField => [$exception->getMessage()],
            ]);
        } catch (ValidationException $exception) {
            $this->cleanupFailedApplicationCreation($application);
            throw $exception;
        }

        // TODO: Отправка в 1C через очередь
        // TODO: Отправка уведомлений через вебхуки

        return new ApplicationResource($application->load(['city', 'clinic', 'branch', 'cabinet', 'doctor']));
    }

    /**
     * Проверяет доступность слота 1С перед переходом к форме.
     */
    public function checkSlot(Request $request)
    {
        $validated = $request->validate([
            'clinic_id' => 'required|exists:clinics,id',
            'branch_id' => 'required|exists:branches,id',
            'doctor_id' => 'required|exists:doctors,id',
            'onec_slot_id' => 'required|string|max:191',
        ]);

        // Здесь проверяем, что пользователь всё ещё бронирует в корректном филиале + что пуш режим активен.
        $branch = Branch::with(['clinic', 'integrationEndpoint'])->find($validated['branch_id']);
        $clinic = $branch?->clinic ?? Clinic::query()->find($validated['clinic_id']);

        if (! $branch || ! $clinic) {
            throw ValidationException::withMessages([
                'branch_id' => ['Указан некорректный филиал или клиника.'],
            ]);
        }

        $endpoint = $branch->integrationEndpoint;
        $requiresOneC = $endpoint
            && $endpoint->type === IntegrationEndpoint::TYPE_ONEC
            && $endpoint->is_active
            && $branch->isOnecPushMode();

        if (! $requiresOneC) {
            return response()->json(['status' => 'ok']);
        }

        // Слот должен принадлежать тому же филиалу/клинике и быть свободным.
        $slot = OnecSlot::query()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->where('external_slot_id', $validated['onec_slot_id'])
            ->first();

        if (
            ! $slot
            || $slot->status !== OnecSlot::STATUS_FREE
            || ($slot->doctor_id && (int) $slot->doctor_id !== (int) $validated['doctor_id'])
        ) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Этот слот только что заняли в 1С. Выберите другое время.'],
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Display the specified application.
     */
    public function show(Application $application)
    {
        return new ApplicationResource($application->load(['city', 'clinic', 'branch', 'cabinet', 'doctor']));
    }

    /**
     * Update the specified application.
     */
    public function update(Request $request, Application $application)
    {
        $validated = $request->validate([
            'city_id' => 'sometimes|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'branch_id' => 'nullable|exists:branches,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'cabinet_id' => 'nullable|exists:cabinets,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'birth_date' => 'nullable|string|max:30',
            'phone' => 'sometimes|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'send_to_1c' => 'boolean',
            'appointment_datetime' => 'nullable|date_format:Y-m-d H:i',
            'onec_slot_id' => 'nullable|string|max:191',
            'comment' => 'nullable|string|max:500',
            'appointment_source' => 'nullable|string|max:100',
        ]);

        $this->normalizeBirthDate($validated);

        $branch = $application->branch;
        $clinic = $application->clinic;

        [$requiresOneC] = $this->determineIntegrationContext($branch, $clinic);

        [$slotExternalId, $commentForOneC, $appointmentSource] = $this->extractIntegrationFields($validated);

        if ($requiresOneC && ! $slotExternalId && $branch && $branch->isOnecPushMode()) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Для переноса записи 1С выберите слот из календаря.'],
            ]);
        }

        try {
            $application->update($validated);
            $application->refresh();

            if ($requiresOneC && $branch && $clinic) {
                if (filled($application->external_appointment_id)) {
                    $this->cancelExistingAppointment($application);
                    $application->refresh();
                }

                $this->bookOneCApplication(
                    application: $application,
                    clinic: $clinic,
                    branch: $branch,
                    slotExternalId: $slotExternalId,
                    manualBooking: false,
                    comment: $commentForOneC,
                    appointmentSource: $appointmentSource,
                    logContext: 'update'
                );

                $application->refresh();
            }
        } catch (OneCBookingException $exception) {
            report($exception);

            $errorField = $slotExternalId ? 'onec_slot_id' : 'appointment_datetime';

            throw ValidationException::withMessages([
                $errorField => [$exception->getMessage()],
            ]);
        }

        return new ApplicationResource($application->load(['city', 'clinic', 'branch', 'cabinet', 'doctor']));
    }

    /**
     * Remove the specified application.
     */
    public function destroy(Application $application)
    {
        $forceDelete = (bool) request()->boolean('force_local_delete');

        if ($forceDelete) {
            // Пользователь подтвердил, что в 1С запись уже удалена. Просто убираем локальную копию.
            if ($application->integration_type !== Application::INTEGRATION_TYPE_ONEC) {
                abort(409, 'Принудительное удаление доступно только для заявок 1С.');
            }

            // Пользователь подтвердил удаление только в нашем приложении (1С запись уже удалила).
            $application->delete();

            return response()->json(null, 204);
        }

        if ($application->integration_type === Application::INTEGRATION_TYPE_ONEC && ! $application->external_appointment_id) {
            abort(403, 'Эту запись создала 1С. Удаление недоступно.');
        }

        if ($application->integration_type === Application::INTEGRATION_TYPE_ONEC && $application->external_appointment_id) {
            try {
                $this->bookingService->cancel($application);
            } catch (OneCBookingException $exception) {
                report($exception);

                $conflict = $this->cancellationConflictResolver->buildConflictPayload($exception);

                if ($conflict) {
                    // Возвращаем 409, чтобы фронт показал предупреждение и кнопку «Удалить и здесь?».
                    return response()->json($conflict, 409);
                }

                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        $application->delete();

        return response()->json(null, 204);
    }

    protected function normalizeBirthDate(array &$payload, string $field = 'birth_date'): void
    {
        if (empty($payload[$field])) {
            return;
        }

        try {
            $payload[$field] = Carbon::parse((string) $payload[$field])->format('Y-m-d');
        } catch (\Throwable) {
            $payload[$field] = substr((string) $payload[$field], 0, 15);
        }
    }

    protected function resolveBranchAndClinic(?int $branchId, ?int $clinicId): array
    {
        $branch = $branchId
            ? Branch::with(['clinic', 'integrationEndpoint'])->find($branchId)
            : null;

        $clinic = $branch?->clinic;

        if (! $clinic && $clinicId) {
            $clinic = Clinic::query()->find($clinicId);
        }

        return [$branch, $clinic];
    }

    protected function determineIntegrationContext(?Branch $branch, ?Clinic $clinic): array
    {
        $endpoint = $branch?->integrationEndpoint;

        $requiresOneC = $endpoint
            && $endpoint->type === IntegrationEndpoint::TYPE_ONEC
            && $endpoint->is_active;

        $isPushMode = $branch?->isOnecPushMode() ?? $clinic?->isOnecPushMode() ?? false;

        return [$requiresOneC, $isPushMode];
    }

    protected function extractIntegrationFields(array &$payload): array
    {
        return [
            Arr::pull($payload, 'onec_slot_id'),
            Arr::pull($payload, 'comment'),
            Arr::pull($payload, 'appointment_source'),
        ];
    }

    protected function validateBookingMode(bool $requiresOneC, bool $manualOnecBooking, ?string $slotExternalId, array $validated): void
    {
        if (! $requiresOneC) {
            return;
        }

        if ($manualOnecBooking) {
            if (empty($validated['appointment_datetime'])) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => ['Для записи выберите дату и время.'],
                ]);
            }

            return;
        }

        if (! $slotExternalId) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Для записи выберите свободный слот.'],
            ]);
        }
    }

    protected function shouldUseManualBooking(bool $requiresOneC, bool $isPushMode, ?string $slotExternalId): bool
    {
        return $requiresOneC && $isPushMode && empty($slotExternalId);
    }

    protected function bookOneCApplication(
        Application $application,
        ?Clinic $clinic,
        ?Branch $branch,
        ?string $slotExternalId,
        bool $manualBooking,
        ?string $comment,
        ?string $appointmentSource,
        string $logContext,
        array $extraPayload = []
    ): void {
        if ($manualBooking && $branch) {
            $this->bookManualFlow($application, $branch, $comment, $appointmentSource, $logContext);

            return;
        }

        if (! $slotExternalId) {
            return;
        }

        $this->bookSlotFlow($application, $clinic, $branch, $slotExternalId, $logContext, $extraPayload);
    }

    protected function bookSlotFlow(
        Application $application,
        ?Clinic $clinic,
        ?Branch $branch,
        string $slotExternalId,
        string $logContext,
        array $extraPayload = []
    ): void {
        $slot = $this->findSlotOrFail($clinic, $branch, $slotExternalId, $application);

        Log::info("OneC booking via {$logContext} (slot)", array_filter([
            'application_id' => $application->id,
            'slot_id' => $slot->id,
            'previous_external_id' => $application->external_appointment_id,
        ]));

        $this->bookingService->book($application, $slot, $extraPayload);
        $application->refresh();
    }

    protected function bookManualFlow(
        Application $application,
        Branch $branch,
        ?string $comment,
        ?string $appointmentSource,
        string $logContext
    ): void {
        Log::info("OneC booking via {$logContext} (manual)", array_filter([
            'application_id' => $application->id,
            'branch_id' => $branch->id,
            'previous_external_id' => $application->external_appointment_id,
        ]));

        $this->bookingService->bookDirect($application, $branch, [
            'comment' => $comment,
            'appointment_source' => $appointmentSource ?? 'Приложение',
        ]);
        $application->refresh();
    }

    protected function findSlotOrFail(?Clinic $clinic, ?Branch $branch, string $slotExternalId, Application $application): OnecSlot
    {
        $slot = OnecSlot::query()
            ->when($clinic?->id, fn ($query, $clinicId) => $query->where('clinic_id', $clinicId))
            ->when($branch?->id, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where('external_slot_id', $slotExternalId)
            ->first();

        if (! $slot) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Выбранный слот недоступен. Попробуйте обновить расписание.'],
            ]);
        }

        if ($slot->status !== OnecSlot::STATUS_FREE) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Этот слот только что заняли в 1С. Выберите другое время.'],
            ]);
        }

        if (
            $application->doctor_id
            && $slot->doctor_id
            && (int) $slot->doctor_id !== (int) $application->doctor_id
        ) {
            throw ValidationException::withMessages([
                'onec_slot_id' => ['Слот не соответствует выбранному врачу. Обновите расписание и выберите заново.'],
            ]);
        }

        return $slot;
    }

    protected function shouldCancelBeforeRebooking(bool $requiresOneC, Application $application): bool
    {
        return $requiresOneC
            && filled($application->external_appointment_id);
    }

    protected function cancelExistingAppointment(Application $application): void
    {
        Log::info('OneC booking update: cancelling existing appointment before rebooking', [
            'application_id' => $application->id,
            'external_appointment_id' => $application->external_appointment_id,
        ]);

        $this->bookingService->cancel($application);
        $application->refresh();

        $application->forceFill([
            'external_appointment_id' => null,
            'integration_payload' => null,
            'integration_status' => null,
        ])->save();
    }

    protected function cleanupFailedApplicationCreation(?Application $application): void
    {
        if (! $application || ! $application->exists) {
            return;
        }

        try {
            $application->delete();
        } catch (\Throwable $exception) {
            Log::error('Не удалось удалить локальную заявку после ошибки синхронизации с 1С.', [
                'application_id' => $application->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
