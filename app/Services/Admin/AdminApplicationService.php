<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\Branch;
use App\Models\IntegrationEndpoint;
use App\Models\OnecSlot;
use App\Services\OneC\Exceptions\OneCBookingException;
use App\Services\OneC\OneCBookingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminApplicationService
{
    public function __construct(private readonly OneCBookingService $bookingService) {}

    public function create(array $data, array $options = []): Application
    {
        $slotExternalId = Arr::get($options, 'onec_slot_id');
        $comment = Arr::get($options, 'comment');
        $appointmentSource = Arr::get($options, 'appointment_source', 'Админка');

        if (array_key_exists('onec_slot_id', $data)) {
            unset($data['onec_slot_id']);
        }

        return DB::transaction(function () use ($data, $slotExternalId, $comment, $appointmentSource) {
            $application = Application::create($data);
            $this->createInOneC($application, $slotExternalId, $comment, $appointmentSource);

            return $application;
        });
    }

    public function update(Application $application, array $data, array $options = []): Application
    {
        $slotExternalId = Arr::get($options, 'onec_slot_id');
        $comment = Arr::get($options, 'comment');
        $appointmentSource = Arr::get($options, 'appointment_source', 'Админка');

        if (array_key_exists('onec_slot_id', $data)) {
            unset($data['onec_slot_id']);
        }

        return DB::transaction(function () use ($application, $data, $slotExternalId, $comment, $appointmentSource) {
            $application->update($data);
            $application->refresh();

            $this->rebookInOneC($application, $slotExternalId, $comment, $appointmentSource);

            return $application;
        });
    }

    protected function rebookInOneC(Application $application, ?string $slotExternalId, ?string $comment, string $appointmentSource): void
    {
        $branch = $application->branch;
        $clinic = $branch?->clinic ?? $application->clinic;

        if (! $branch || ! $clinic) {
            return;
        }

        $endpoint = $branch->integrationEndpoint;

        if (! $endpoint || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC || ! $endpoint->is_active) {
            return;
        }

        if ($application->external_appointment_id) {
            \Log::info('OneC rebook: cancel existing booking', [
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
            \Log::info('OneC rebook: cleared external fields', [
                'application_id' => $application->id,
            ]);
        }

        if ($slotExternalId) {
            $slot = OnecSlot::query()
                ->where('clinic_id', $clinic->id)
                ->where('branch_id', $branch->id)
                ->where('external_slot_id', $slotExternalId)
                ->first();

            if (! $slot) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'Выбранный слот недоступен. Обновите расписание и попробуйте снова.',
                ]);
            }
            \Log::info('OneC rebook: booking by slot', [
                'application_id' => $application->id,
                'slot_external_id' => $slotExternalId,
                'slot_start_at' => $slot->start_at,
            ]);
            $this->bookingService->book($application, $slot, [
                'comment' => $comment,
                'appointment_source' => $appointmentSource,
            ]);

            return;
        }

        if (! $application->appointment_datetime) {
            throw ValidationException::withMessages([
                'appointment_datetime' => 'Укажите дату и время приема перед записью в 1С.',
            ]);
        }

        $this->bookingService->bookDirect($application, $branch, [
            'comment' => $comment,
            'appointment_source' => $appointmentSource,
        ]);
        \Log::info('OneC rebook: bookDirect', [
            'application_id' => $application->id,
            'appointment_datetime' => $application->appointment_datetime,
        ]);
    }

    public function cancelOneCBooking(Application $application): void
    {
        $branch = $application->branch;
        $endpoint = $branch?->integrationEndpoint;

        if (! $branch || ! $endpoint || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC || ! $endpoint->is_active) {
            throw new OneCBookingException('Для заявки не настроена интеграция с 1С.');
        }

        if (! $application->external_appointment_id) {
            throw new OneCBookingException('У заявки нет внешнего идентификатора 1С.');
        }

        $this->bookingService->cancel($application);

        $application->refresh();

        $application->forceFill([
            'external_appointment_id' => null,
            'integration_payload' => null,
            'integration_status' => null,
        ])->save();
    }


    protected function createInOneC(Application $application, ?string $slotExternalId, ?string $comment, string $appointmentSource): void
    {
        $branch = $application->branch;
        $clinic = $branch?->clinic ?? $application->clinic;

        if (! $branch || ! $clinic) {
            return;
        }

        $endpoint = $branch->integrationEndpoint;

        if (! $endpoint || $endpoint->type !== IntegrationEndpoint::TYPE_ONEC || ! $endpoint->is_active) {
            return;
        }

        if ($slotExternalId) {
            $slot = OnecSlot::query()
                ->where('clinic_id', $clinic->id)
                ->where('branch_id', $branch->id)
                ->where('external_slot_id', $slotExternalId)
                ->first();

            if (! $slot || $slot->status !== OnecSlot::STATUS_FREE) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'Выбранный слот недоступен. Обновите расписание и попробуйте снова.',
                ]);
            }
            \Log::info('OneC create: booking by slot', [
                'application_id' => $application->id,
                'slot_external_id' => $slotExternalId,
                'slot_start_at' => $slot->start_at,
            ]);
            $this->bookingService->book($application, $slot, [
                'comment' => $comment,
                'appointment_source' => $appointmentSource,
            ]);

            return;
        }

        if (! $application->appointment_datetime) {
            throw ValidationException::withMessages([
                'appointment_datetime' => 'Укажите дату и время приема перед записью в 1С.',
            ]);
        }

        $this->bookingService->bookDirect($application, $branch, [
            'comment' => $comment,
            'appointment_source' => $appointmentSource,
        ]);
        \Log::info('OneC create: bookDirect', [
            'application_id' => $application->id,
            'appointment_datetime' => $application->appointment_datetime,
        ]);
    }

    public function branchRequiresOneCSlot(int $branchId): bool
    {
        $branch = Branch::with('integrationEndpoint')->find($branchId);

        if (! $branch) {
            return false;
        }

        $endpoint = $branch->integrationEndpoint;

        return $branch->isOnecPushMode()
            && $endpoint
            && $endpoint->type === IntegrationEndpoint::TYPE_ONEC
            && $endpoint->is_active;
    }
}
