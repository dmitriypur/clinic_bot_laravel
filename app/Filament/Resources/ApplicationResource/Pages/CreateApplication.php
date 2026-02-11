<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Services\Admin\AdminApplicationService;
use App\Services\OneC\Exceptions\OneCBookingException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateApplication extends CreateRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        if (! isset($data['source'])) {
            $data['source'] = null;
        }

        $onecSlotId = $data['onec_slot_id'] ?? null;
        unset($data['onec_slot_id']);

        $branchId = $data['branch_id'] ?? null;

        if ($branchId && app(AdminApplicationService::class)->branchRequiresOneCSlot((int) $branchId) && ! $onecSlotId) {
            Notification::make()
                ->title('Выберите время в календаре')
                ->body('Для филиала с интеграцией 1С запись создаётся только через слот. Пожалуйста, используйте календарь.')
                ->danger()
                ->send();

            throw ValidationException::withMessages([
                'appointment_datetime' => 'Выберите время через календарь (слот 1С) для записи в этот филиал.',
            ]);
        }

        try {
            return app(AdminApplicationService::class)->create($data, [
                'onec_slot_id' => $onecSlotId,
                'appointment_source' => 'Админка',
            ]);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Запись не сохранена')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->setErrorBag($exception->validator?->errors() ?? $exception->errors());
            throw $exception;
        } catch (OneCBookingException $exception) {
            Notification::make()
                ->title('1С отклонила запись')
                ->body($exception->getMessage())
                ->danger()
                ->send();
            throw ValidationException::withMessages([
                'appointment_datetime' => $exception->getMessage(),
            ]);
        }
    }
}
