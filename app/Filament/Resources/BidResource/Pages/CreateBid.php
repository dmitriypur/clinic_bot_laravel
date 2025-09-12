<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\BidCalendarWidget;
use App\Models\Application;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\View\View;

class CreateBid extends CreateRecord
{
    protected static string $resource = BidResource::class;

    /**
     * Инициализация при монтировании компонента
     */
    public function mount(): void
    {
        parent::mount();
        
        // Отправляем начальные данные формы в календарь
        $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
    }

    /**
     * Виджеты в заголовке страницы
     * Включает календарь для выбора времени приема
     */
    protected function getFooterWidgets(): array
    {
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
        if (in_array($property, ['data.city_id', 'data.clinic_id', 'data.branch_id', 'data.doctor_id', 'data.cabinet_id'])) {
            $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
        }
    }

    /**
     * Подключаем JavaScript для интеграции с календарем
     */
    public function getFooter(): ?View
    {
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
            $data['status_id'] = $data['status_id'] ?? 1; // Статус "Новая" по умолчанию
            
            // Создаем новую заявку
            $application = Application::create($data);
            
            if ($another) {
                // Если создаем еще одну заявку - очищаем форму
                $this->form->fill();
                $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
            } else {
                // Перенаправляем на страницу редактирования
                $this->redirect(static::getResource()::getUrl('edit', ['record' => $application]));
            }
        } catch (\Exception $e) {
            // Логируем ошибку
            \Log::error('Ошибка создания заявки: ' . $e->getMessage());
            
            // Показываем уведомление об ошибке
            \Filament\Notifications\Notification::make()
                ->title('Ошибка создания заявки')
                ->body('Произошла ошибка при создании заявки. Попробуйте еще раз.')
                ->danger()
                ->send();
        }
    }
}
