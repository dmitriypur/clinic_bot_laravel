<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Services\Admin\AdminApplicationService;
use App\Services\OneC\Exceptions\OneCBookingException;
use App\Models\OnecSlot;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditApplication extends EditRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $data['onec_slot_id'] ??= $this->resolveOnecSlotId();

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $onecSlotId = $data['onec_slot_id'] ?? null;
        unset($data['onec_slot_id']);

        $branchId = $data['branch_id'] ?? $this->record->branch_id;

        if ($branchId && app(AdminApplicationService::class)->branchRequiresOneCSlot((int) $branchId) && ! $onecSlotId) {
            Notification::make()
                ->title('Выберите время в календаре')
                ->body('Для филиала с интеграцией 1С редактирование возможно только через слот 1С.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'appointment_datetime' => 'Выберите слот в календаре перед сохранением.',
            ]);
        }

        try {
            app(AdminApplicationService::class)->update($this->record, $data, [
                'onec_slot_id' => $onecSlotId,
                'appointment_source' => 'Админка',
            ]);

            return $this->record;
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Запись не сохранена')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->setErrorBag($exception->validator?->errors() ?? $exception->errors());
        } catch (OneCBookingException $exception) {
            Notification::make()
                ->title('1С отклонила запись')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return $this->record;
    }

    protected function resolveOnecSlotId(): ?string
    {
        $externalAppointmentId = $this->record->external_appointment_id;

        if (! $externalAppointmentId) {
            return null;
        }

        return OnecSlot::query()
            ->where('booking_uuid', $externalAppointmentId)
            ->orWhere('external_slot_id', $externalAppointmentId)
            ->value('external_slot_id');
    }
}
