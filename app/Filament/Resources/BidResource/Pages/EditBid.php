<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\BidCalendarWidget;
use App\Models\Application;
use App\Models\ApplicationStatus;
use App\Support\CalendarSettings;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class EditBid extends EditRecord
{
    protected static string $resource = BidResource::class;

    /**
     * Слушатели событий Livewire
     */
    protected $listeners = ["slotSelected", "updateApplicationFromSlot"];

    /**
     * Инициализация при монтировании компонента
     */
    public function mount(string|int $record): void
    {
        parent::mount($record);

        if ($this->isCalendarEnabled()) {
            // Отправляем начальные данные формы в календарь
            $this->dispatch("formDataUpdated", $this->getFormDataForCalendar());
        }
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Виджеты в заголовке страницы
     * Включает календарь для выбора времени приема
     */
    protected function getFooterWidgets(): array
    {
        if (!$this->isCalendarEnabled()) {
            return [];
        }

        return [
            BidCalendarWidget::make([
                "formData" => $this->getFormDataForCalendar(),
            ]),
        ];
    }

    /**
     * Получает данные формы для передачи в календарь
     */
    protected function getFormDataForCalendar(): array
    {
        $data = $this->form->getState();

        return [
            "city_id" => $data["city_id"] ?? null,
            "clinic_id" => $data["clinic_id"] ?? null,
            "branch_id" => $data["branch_id"] ?? null,
            "doctor_id" => $data["doctor_id"] ?? null,
            "cabinet_id" => $data["cabinet_id"] ?? null,
        ];
    }

    /**
     * Обновляет календарь при изменении данных формы
     */
    public function updated($property): void
    {
        // Обновляем календарь при изменении полей фильтрации
        if (
            $this->isCalendarEnabled() &&
            in_array($property, [
                "data.city_id",
                "data.clinic_id",
                "data.branch_id",
                "data.doctor_id",
                "data.cabinet_id",
            ])
        ) {
            $this->dispatch("formDataUpdated", $this->getFormDataForCalendar());
        }

        // При изменении статуса обновляем виджеты
        if ($property === "data.status_id") {
            $this->dispatch('$refresh');
        }
    }

    /**
     * Обработчик выбора слота из календаря
     */
    public function slotSelected($slotData): void
    {
        if (!$this->isCalendarEnabled()) {
            return;
        }

        // Получаем текущие данные формы
        $currentData = $this->form->getState();

        // Обновляем только поля связанные с календарем, сохраняя уже заполненные данные
        $this->form->fill([
            "city_id" =>
                $slotData["city_id"] ?? ($currentData["city_id"] ?? null),
            "clinic_id" =>
                $slotData["clinic_id"] ?? ($currentData["clinic_id"] ?? null),
            "branch_id" =>
                $slotData["branch_id"] ?? ($currentData["branch_id"] ?? null),
            "cabinet_id" =>
                $slotData["cabinet_id"] ?? ($currentData["cabinet_id"] ?? null),
            "doctor_id" =>
                $slotData["doctor_id"] ?? ($currentData["doctor_id"] ?? null),
            "appointment_datetime" => $slotData["appointment_datetime"] ?? null,
            // Сохраняем уже заполненные обязательные поля
            "full_name" => $currentData["full_name"] ?? null,
            "full_name_parent" => $currentData["full_name_parent"] ?? null,
            "phone" => $currentData["phone"] ?? null,
            "birth_date" => $currentData["birth_date"] ?? null,
            "promo_code" => $currentData["promo_code"] ?? null,
            "status_id" => $currentData["status_id"] ?? null,
        ]);

        // Обновляем календарь с новыми данными
        $this->dispatch("formDataUpdated", $this->getFormDataForCalendar());
    }

    /**
     * Подключаем JavaScript для интеграции с календарем
     */
    public function getFooter(): ?View
    {
        if (!$this->isCalendarEnabled()) {
            return null;
        }

        return view("filament.pages.bid-calendar-integration");
    }

    /**
     * Обработчик обновления заявки данными из календаря
     */
    public function updateApplicationFromSlot($slotData): void
    {
        if (!$this->isCalendarEnabled()) {
            return;
        }

        try {
            // Получаем текущие данные формы
            $currentData = $this->form->getState();

            // Объединяем данные формы с данными слота
            $applicationData = array_merge($currentData, $slotData);

            // Устанавливаем статус "Запись на прием"
            $appointmentStatus = \App\Models\ApplicationStatus::where(
                "slug",
                "appointment",
            )->first();
            if ($appointmentStatus) {
                $applicationData["status_id"] = $appointmentStatus->id;
            }

            // Обновляем форму с новыми данными
            $this->form->fill($applicationData);

            // Показываем уведомление об успехе
            Notification::make()
                ->title("Время выбрано")
                ->body(
                    'Время приема выбрано из календаря. Статус изменен на "Запись на прием"',
                )
                ->success()
                ->send();
        } catch (\Exception $e) {
            // Логируем ошибку
            \Log::error(
                "Ошибка обновления заявки данными слота в EditBid: " .
                    $e->getMessage(),
                [
                    "slotData" => $slotData,
                    "currentRecord" => $this->record->id,
                    "trace" => $e->getTraceAsString(),
                ],
            );

            // Показываем уведомление об ошибке
            Notification::make()
                ->title("Ошибка обновления")
                ->body("Ошибка: " . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Сохранение изменений заявки
     */
    public function save(
        bool $shouldRedirect = true,
        bool $shouldSendSavedNotification = true,
    ): void {
        try {
            $data = $this->form->getState();

            if (isset($data["phone"])) {
                $digitsOnly = preg_replace("/\D+/", "", $data["phone"]);
                $data["phone"] = $digitsOnly ?: null;
            }

            // Валидация: если выбран город, то клиника обязательна
            if (!empty($data["city_id"]) && empty($data["clinic_id"])) {
                \Filament\Notifications\Notification::make()
                    ->title("Ошибка валидации")
                    ->body(
                        "Поле клиника обязательно для заполнения при выборе города.",
                    )
                    ->danger()
                    ->send();
                return;
            }

            // Если статус "Новая" или "Отменен" - очищаем appointment_datetime
            $status = ApplicationStatus::find($data["status_id"]);
            if ($status && in_array($status->slug, ["new", "bid_cancelled"])) {
                $data["appointment_datetime"] = null;
            }

            // Обновляем заявку
            $this->record->update($data);

            if ($shouldSendSavedNotification) {
                // Показываем уведомление об успешном сохранении
                \Filament\Notifications\Notification::make()
                    ->title("Заявка сохранена")
                    ->body("Изменения в заявке успешно сохранены.")
                    ->success()
                    ->send();
            }

            if ($shouldRedirect) {
                // Перенаправляем на список заявок
                $this->redirect(static::getResource()::getUrl("index"));
            }
        } catch (\Exception $e) {
            // Логируем ошибку с деталями
            \Log::error("Ошибка сохранения заявки: " . $e->getMessage(), [
                "data" => $data,
                "record_id" => $this->record->id,
                "trace" => $e->getTraceAsString(),
            ]);

            // Показываем уведомление об ошибке с деталями
            \Filament\Notifications\Notification::make()
                ->title("Ошибка сохранения заявки")
                ->body("Ошибка: " . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Проверяет, включен ли календарь заявок.
     */
    protected function isCalendarEnabled(): bool
    {
        return CalendarSettings::isEnabledForUser(Auth::user());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data["phone"])) {
            $digitsOnly = preg_replace("/\D+/", "", $data["phone"]);
            $data["phone"] = $digitsOnly ?: null;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data["phone"])) {
            $digitsOnly = preg_replace("/\D+/", "", $data["phone"]);
            $data["phone"] = $digitsOnly ?: null;
        }

        return $data;
    }
}
