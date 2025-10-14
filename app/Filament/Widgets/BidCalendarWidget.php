<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Carbon\Carbon;

/**
 * Виджет календаря для BidResource
 * 
 * Адаптированная версия AppointmentCalendarWidget для работы в формах создания/редактирования заявок.
 * Основные особенности:
 * - Отображает календарь смен врачей с временными слотами
 * - Показывает занятые и свободные слоты разными цветами
 * - Позволяет выбирать время через календарь для заполнения поля appointment_datetime
 * - Интегрируется с формами BidResource
 * - Разграничивает права доступа по ролям пользователей
 */
class BidCalendarWidget extends BaseAppointmentCalendarWidget
{
    /**
     * Данные формы для фильтрации календаря
     * Получаются из родительского компонента (страницы формы)
     */
    public array $formData = [];
    
    /**
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'formDataUpdated', 'slotSelected', 'updateApplicationFromSlot'];
    
    /**
     * Конфигурация календаря FullCalendar
     * 
     * Настройки отображения и поведения календаря:
     * - Интерфейс на русском языке
     * - Рабочие часы с 8:00 до 20:00
     * - Слоты по 15 минут для точного планирования
     * - Отключено стандартное редактирование (используем свои формы)
     * - Несколько видов отображения (неделя, день, месяц, список)
     */
    public function config(): array
    {
        return $this->makeAppointmentCalendarConfig();
    }

    /**
     * JavaScript код для обработки монтирования событий
     * Добавляет подсказки с информацией о кабинете
     */
    public function eventDidMount(): string
    {
        return '
            function(info) {
                const extendedProps = info.event.extendedProps;
                if (extendedProps && extendedProps.cabinet_name) {
                    
                    // Создаем кастомную подсказку
                    const tooltip = document.createElement("div");
                    tooltip.className = "custom-tooltip";
                    tooltip.innerHTML = `
                        <div style="padding: 10px; background: #f5ad3d; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 12px; line-height: 1.4; max-width: 220px;">
                            <strong style="color: rgba(43, 43, 43, 0.9); font-weight: 600;">${extendedProps.cabinet_name}</strong><br>
                            <span style="color: rgba(43, 43, 43, 0.9);">Филиал: ${extendedProps.branch_name || "Не указан"}</span><br>
                            <span style="color: rgba(43, 43, 43,0.9);">Клиника: ${extendedProps.clinic_name || "Не указана"}</span><br>
                            <span style="color: rgba(43, 43, 43,0.9);">Врач: ${extendedProps.doctor_name || "Не назначен"}</span>
                        </div>
                    `;
                    
                    tooltip.style.cssText = `
                        position: fixed !important;
                        z-index: 99999 !important;
                        opacity: 0;
                        transition: opacity 0.2s ease;
                        pointer-events: none;
                        display: none;
                    `;
                    
                    document.body.appendChild(tooltip);
                    
                    // Обработчики событий
                    let showTimeout;
                    let hideTimeout;
                    
                    info.el.addEventListener("mouseenter", function(e) {
                        clearTimeout(hideTimeout);
                        showTimeout = setTimeout(() => {
                            const rect = info.el.getBoundingClientRect();
                            const tooltipRect = tooltip.getBoundingClientRect();
                            
                            // Позиционируем справа от слота
                            let left = rect.right + 10; // 10px отступ справа от слота
                            let top = rect.top + (rect.height / 2) - (tooltipRect.height / 2) - 40; // По центру по вертикали, но на 40px выше
                            
                            // Проверяем, не выходит ли подсказка за границы экрана
                            if (left + tooltipRect.width > window.innerWidth - 10) {
                                // Если не помещается справа, показываем слева
                                left = rect.left - tooltipRect.width - 10;
                            }
                            
                            // Проверяем вертикальные границы
                            if (top < 10) {
                                top = 10;
                            } else if (top + tooltipRect.height > window.innerHeight - 10) {
                                top = window.innerHeight - tooltipRect.height - 10;
                            }
                            
                            tooltip.style.display = "block";
                            tooltip.style.left = left + "px";
                            tooltip.style.top = top + "px";
                            tooltip.style.opacity = "1";
                        }, 300);
                    });
                    
                    info.el.addEventListener("mouseleave", function(e) {
                        clearTimeout(showTimeout);
                        hideTimeout = setTimeout(() => {
                            tooltip.style.opacity = "0";
                            setTimeout(() => {
                                tooltip.style.display = "none";
                            }, 200);
                        }, 100);
                    });
                    
                    // Очистка при удалении события
                    info.el.addEventListener("remove", function() {
                        if (tooltip.parentNode) {
                            tooltip.parentNode.removeChild(tooltip);
                        }
                    });
                    
                }
            }
        ';
    }

    /**
     * Получить события для календаря
     * 
     * Основная логика:
     * 1. Получаем смены врачей в запрошенном диапазоне дат
     * 2. Фильтруем по данным формы (клиника, филиал, врач, кабинет)
     * 3. Для каждой смены генерируем временные слоты
     * 4. Проверяем занятость каждого слота
     * 5. Формируем события для календаря с цветовой индикацией
     * 
     * @param array $fetchInfo Массив с датами начала и конца периода
     * @return array Массив событий для календаря
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $filters = $this->createFiltersFromFormData();

        return $this->generateCalendarEvents($fetchInfo, $filters);
    }

    /**
     * Обработчик обновления данных формы
     */
    public function formDataUpdated($formData): void
    {
        $this->formData = $formData;
        $this->refreshRecords();
    }

    /**
     * Создает фильтры на основе данных формы
     */
    protected function createFiltersFromFormData(): array
    {
        $filters = [
            'city_ids' => [],
            'clinic_ids' => [],
            'branch_ids' => [],
            'doctor_ids' => [],
            'date_from' => null,
            'date_to' => null,
        ];

        // Если выбран город
        if (!empty($this->formData['city_id'])) {
            $filters['city_ids'] = [$this->formData['city_id']];
        }

        // Если выбрана клиника
        if (!empty($this->formData['clinic_id'])) {
            $filters['clinic_ids'] = [$this->formData['clinic_id']];
        }

        // Если выбран филиал
        if (!empty($this->formData['branch_id'])) {
            $filters['branch_ids'] = [$this->formData['branch_id']];
        }

        // Если выбран врач
        if (!empty($this->formData['doctor_id'])) {
            $filters['doctor_ids'] = [$this->formData['doctor_id']];
        }

        return $filters;
    }

    /**
     * Обработка клика по событию в календаре
     * 
     * Основная логика:
     * 1. Определяет тип слота (занятый или свободный)
     * 2. Для занятых слотов - показывает информацию о записи
     * 3. Для свободных слотов - передает данные в форму для заполнения поля appointment_datetime
     * 4. Проверяет права доступа пользователя
     * 5. Проверяет, не прошла ли запись
     * 
     * @param array $data Данные события календаря
     */
    public function onEventClick(array $data): void
    {
        $user = auth()->user();
        $event = $data;
        $extendedProps = $event['extendedProps'] ?? [];
        
        // Проверяем, не прошла ли запись
        if (isset($extendedProps['is_past']) && $extendedProps['is_past']) {
            Notification::make()
                ->title('Прошедшая запись')
                ->body('Нельзя выбрать прошедшие записи')
                ->warning()
                ->send();
            return;
        }
        
        // Если слот занят - показываем информацию о записи
        if (isset($extendedProps['is_occupied']) && $extendedProps['is_occupied']) {
            $this->onOccupiedSlotClick($extendedProps);
            return;
        }

        // Если слот свободен - передаем данные в форму
        $this->onFreeSlotClick($extendedProps);
    }

    /**
     * Обработка клика по свободному слоту
     * 
     * Автоматически создает заявку со статусом "Запись на прием"
     * 
     * @param array $data Данные слота с информацией о кабинете и времени
     */
    public function onFreeSlotClick(array $data): void
    {
        // Находим смену врача по ID из данных события
        $shift = DoctorShift::with(['cabinet.branch.clinic', 'cabinet.branch.city', 'doctor'])
            ->find($data['shift_id']);

        if (!$shift) {
            Notification::make()
                ->title('Ошибка')
                ->body('Смена врача не найдена')
                ->danger()
                ->send();
            return;
        }

        
        // Сохраняем данные слота в свойстве виджета для передачи в форму
        $slotStart = $data['slot_start'];
        if (is_string($slotStart)) {
            $slotStart = \Carbon\Carbon::parse($slotStart);
        }
        
        // Конвертируем время в часовой пояс приложения (Europe/Moscow)
        $cityId = $shift->cabinet->branch->city_id;
        $slotStartInCity = $slotStart->setTimezone(config('app.timezone', 'Europe/Moscow'));
        
        // Отправляем событие для обновления заявки данными слота
        $this->dispatch('updateApplicationFromSlot', [
            'city_id' => $cityId,
            'clinic_id' => $shift->cabinet->branch->clinic_id,
            'branch_id' => $shift->cabinet->branch_id,
            'cabinet_id' => $shift->cabinet_id,
            'doctor_id' => $shift->doctor_id,
            'appointment_datetime' => $slotStartInCity->format('Y-m-d H:i:s'),
        ]);
        
        // Отладочная информация
        \Log::info('BidCalendarWidget: Slot selected', [
            'original_slot_start' => $slotStart->format('Y-m-d H:i:s'),
            'converted_slot_start' => $slotStartInCity->format('Y-m-d H:i:s'),
            'app_timezone' => config('app.timezone'),
            'shift_id' => $shift->id,
            'cabinet_id' => $shift->cabinet_id,
        ]);
    }

    /**
     * Обработка клика по занятому слоту
     * 
     * Показывает информацию о существующей записи
     * 
     * @param array $data Данные слота с информацией о кабинете и времени
     */
    public function onOccupiedSlotClick(array $data): void
    {
        $user = auth()->user();
        $extendedProps = $data;
        
        // Проверяем, есть ли данные заявки в событии
        if (isset($extendedProps['application_id']) && $extendedProps['application_id']) {
            
            // Используем данные из события, но загружаем полную модель для просмотра
            $application = Application::with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->find($extendedProps['application_id']);
                
            if (!$application) {
                Notification::make()
                    ->title('Ошибка')
                    ->body('Заявка не найдена')
                    ->danger()
                    ->send();
                return;
            }
            
            // Проверяем права доступа
            if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
                Notification::make()
                    ->title('Ошибка доступа')
                    ->body('Вы можете просматривать только заявки своей клиники')
                    ->danger()
                    ->send();
                return;
            } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
                Notification::make()
                    ->title('Ошибка доступа')
                    ->body('Вы можете просматривать только свои заявки')
                    ->danger()
                    ->send();
                return;
            }

            // Показываем информацию о записи
            Notification::make()
                ->title('Занятый слот')
                ->body("Время занято: {$application->full_name} ({$application->phone})")
                ->info()
                ->send();
        }
    }

    /**
     * Действия в заголовке виджета
     * 
     * Календарь не имеет собственных действий - фильтрация происходит через поля формы
     * 
     * @return array Пустой массив действий
     */
    protected function headerActions(): array
    {
        return [];
    }
    
    /**
     * Обработчик события обновления календаря
     * 
     * Вызывается при необходимости принудительного обновления событий календаря.
     * Например, после создания, редактирования или удаления заявки.
     * Использует Livewire dispatch для обновления компонента.
     */
    public function refetchEvents()
    {
        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }
    
    /**
     * Принудительное обновление календаря
     */
    public function forceRefresh()
    {
        // Очищаем кэш календаря
        $this->clearCalendarCache();
        
        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }
    
    /**
     * Очистка кэша календаря
     */
    private function clearCalendarCache()
    {
        // Очищаем все ключи кэша календаря
        $keys = \Illuminate\Support\Facades\Cache::get('calendar_cache_keys', []);
        
        foreach ($keys as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        
        // Очищаем ключ со списком ключей
        \Illuminate\Support\Facades\Cache::forget('calendar_cache_keys');
    }
}
