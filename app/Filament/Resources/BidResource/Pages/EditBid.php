<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\BidCalendarWidget;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\View\View;

class EditBid extends EditRecord
{
    protected static string $resource = BidResource::class;

    /**
     * Инициализация при монтировании компонента
     */
    public function mount(string|int $record): void
    {
        parent::mount($record);
        
        // Отправляем начальные данные формы в календарь
        $this->dispatch('formDataUpdated', $this->getFormDataForCalendar());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
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
     * Сохранение изменений заявки
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            $data = $this->form->getState();
            
            // Обновляем заявку
            $this->record->update($data);
            
            if ($shouldSendSavedNotification) {
                // Показываем уведомление об успешном сохранении
                \Filament\Notifications\Notification::make()
                    ->title('Заявка сохранена')
                    ->body('Изменения в заявке успешно сохранены.')
                    ->success()
                    ->send();
            }
            
            if ($shouldRedirect) {
                // Перенаправляем на список заявок
                $this->redirect(static::getResource()::getUrl('index'));
            }
        } catch (\Exception $e) {
            // Логируем ошибку
            \Log::error('Ошибка сохранения заявки: ' . $e->getMessage());
            
            // Показываем уведомление об ошибке
            \Filament\Notifications\Notification::make()
                ->title('Ошибка сохранения заявки')
                ->body('Произошла ошибка при сохранении заявки. Попробуйте еще раз.')
                ->danger()
                ->send();
        }
    }
}
