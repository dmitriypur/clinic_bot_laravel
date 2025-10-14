<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\BidCalendarWidget;
use App\Models\Application;
use App\Models\SystemSetting;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\View\View;

class CreateBid extends CreateRecord
{
    protected static string $resource = BidResource::class;

    /**
     * Слушатели событий Livewire
     */
    protected $listeners = ['slotSelected', 'updateApplicationFromSlot'];

    /**
     * Инициализация при монтировании компонента
     */
    public function mount(): void
    {
        parent::mount();
        
        if ($this->isCalendarEnabled()) {
            // Отправляем начальные данные формы в календарь
            $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
        }
    }

    /**
     * Виджеты в заголовке страницы
     * Включает календарь для выбора времени приема
     */
    protected function getFooterWidgets(): array
    {
        if (! $this->isCalendarEnabled()) {
            return [];
        }

        return [
            BidCalendarWidget::make([
                'formData' => $this->getFormDataForCalendar(),
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
            'city_id' => $data['city_id'] ?? null,
            'clinic_id' => $data['clinic_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'doctor_id' => $data['doctor_id'] ?? null,
            'cabinet_id' => $data['cabinet_id'] ?? null,
        ];
    }

    /**
     * Обновляет календарь при изменении данных формы
     */
    public function updated($property): void
    {
        // Обновляем календарь при изменении полей фильтрации
        if ($this->isCalendarEnabled() && in_array($property, ['data.city_id', 'data.clinic_id', 'data.branch_id', 'data.doctor_id', 'data.cabinet_id'])) {
            $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
        }
        
        // При изменении статуса обновляем виджеты
        if ($property === 'data.status_id') {
            $this->dispatch('$refresh');
        }
    }

    /**
     * Обработчик выбора слота из календаря
     */
    public function slotSelected($slotData): void
    {
        if (! $this->isCalendarEnabled()) {
            return;
        }

        // Получаем текущие данные формы
        $currentData = $this->form->getState();
        
        // Обновляем только поля связанные с календарем, сохраняя уже заполненные данные
        $this->form->fill([
            'city_id' => $slotData['city_id'] ?? $currentData['city_id'] ?? null,
            'clinic_id' => $slotData['clinic_id'] ?? $currentData['clinic_id'] ?? null,
            'branch_id' => $slotData['branch_id'] ?? $currentData['branch_id'] ?? null,
            'cabinet_id' => $slotData['cabinet_id'] ?? $currentData['cabinet_id'] ?? null,
            'doctor_id' => $slotData['doctor_id'] ?? $currentData['doctor_id'] ?? null,
            'appointment_datetime' => $slotData['appointment_datetime'] ?? null,
            // Сохраняем уже заполненные обязательные поля
            'full_name' => $currentData['full_name'] ?? null,
            'full_name_parent' => $currentData['full_name_parent'] ?? null,
            'phone' => $currentData['phone'] ?? null,
            'birth_date' => $currentData['birth_date'] ?? null,
            'promo_code' => $currentData['promo_code'] ?? null,
            'status_id' => $currentData['status_id'] ?? null,
        ]);

        // Обновляем календарь с новыми данными
        $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
    }

    /**
     * Обработчик обновления заявки данными из календаря
     */
    public function updateApplicationFromSlot($slotData): void
    {
        if (! $this->isCalendarEnabled()) {
            return;
        }

        try {
            // Получаем текущие данные формы
            $currentData = $this->form->getState();
            
            // Объединяем данные формы с данными слота
            $applicationData = array_merge($currentData, $slotData);
            
            // Устанавливаем статус "Запись на прием"
            $appointmentStatus = \App\Models\ApplicationStatus::where('slug', 'appointment')->first();
            if ($appointmentStatus) {
                $applicationData['status_id'] = $appointmentStatus->id;
            }
            
            // Обновляем форму с новыми данными
            $this->form->fill($applicationData);
            
            // Показываем уведомление об успехе
            Notification::make()
                ->title('Время выбрано')
                ->body('Время приема выбрано из календаря. Статус изменен на "Запись на прием"')
                ->success()
                ->send();
            
        } catch (\Exception $e) {
            // Логируем ошибку
            \Log::error('Ошибка обновления заявки данными слота: ' . $e->getMessage(), [
                'slotData' => $slotData,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Показываем уведомление об ошибке
            Notification::make()
                ->title('Ошибка обновления')
                ->body('Ошибка: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Подключаем JavaScript для интеграции с календарем
     */
    public function getFooter(): ?View
    {
        if (! $this->isCalendarEnabled()) {
            return null;
        }

        return view('filament.pages.bid-calendar-integration');
    }

    /**
     * Создание новой заявки
     */
    public function create(bool $another = false): void
    {
        try {
            $data = $this->form->getState();
            
            // Добавляем значения по умолчанию
            $data['source'] = 'admin';
            $data['send_to_1c'] = false;

            // Устанавливаем статус "Новая" по умолчанию, если не выбран
            if (empty($data['status_id'])) {
                $newStatus = \App\Models\ApplicationStatus::where('slug', 'new')->first();
                $data['status_id'] = $newStatus ? $newStatus->id : null;
            }
            
            // Если статус "Новая" или "Отказался" - очищаем appointment_datetime
            $status = \App\Models\ApplicationStatus::find($data['status_id']);
            if ($status && in_array($status->slug, ['new', 'bid_cancelled'])) {
                $data['appointment_datetime'] = null;
            }
            
            // Создаем новую заявку
            $application = Application::create($data);
            
            if ($another) {
                // Если создаем еще одну заявку - очищаем форму
                $this->form->fill();
                if ($this->isCalendarEnabled()) {
                    $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
                }
            } else {
                // Перенаправляем на страницу редактирования
                $this->redirect(static::getResource()::getUrl('edit', ['record' => $application]));
            }
        } catch (\Exception $e) {
            // Логируем ошибку с деталями
            \Log::error('Ошибка создания заявки: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Показываем уведомление об ошибке с деталями
            \Filament\Notifications\Notification::make()
                ->title('Ошибка создания заявки')
                ->body('Ошибка: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Проверяет, включен ли календарь заявок.
     */
    protected function isCalendarEnabled(): bool
    {
        return SystemSetting::getValue('dashboard_calendar_enabled', true) === true;
    }
}
