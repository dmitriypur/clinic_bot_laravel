<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
use App\Services\CalendarFilterService;
use App\Services\CalendarEventService;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
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
class BidCalendarWidget extends FullCalendarWidget
{
    /**
     * Модель данных для работы с заявками
     */
    public \Illuminate\Database\Eloquent\Model | string | null $model = Application::class;
    
    /**
     * Временное хранилище данных выбранного слота
     * Используется для передачи информации между событиями календаря и формами
     */
    public array $slotData = [];
    
    /**
     * Данные формы для фильтрации календаря
     * Получаются из родительского компонента (страницы формы)
     */
    public array $formData = [];
    
    /**
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'formDataUpdated', 'slotSelected'];
    
    /**
     * Сервисы для работы с фильтрами и событиями
     */
    protected ?CalendarFilterService $filterService = null;
    protected ?CalendarEventService $eventService = null;
    
    public function getFilterService(): CalendarFilterService
    {
        if ($this->filterService === null) {
            $this->filterService = app(CalendarFilterService::class);
        }
        return $this->filterService;
    }
    
    protected function getEventService(): CalendarEventService
    {
        if ($this->eventService === null) {
            $this->eventService = app(CalendarEventService::class);
        }
        return $this->eventService;
    }

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
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
        
        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today', // Кнопки навигации и "Сегодня"
                'center' => 'title', // Заголовок с текущим периодом
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Переключатели видов
            ],
            'initialView' => 'timeGridWeek', // По умолчанию показываем неделю
            'navLinks' => true, // Клик по дате переключает на день
            'editable' => false, // Отключаем стандартное редактирование событий
            'selectable' => false, // Отключаем выбор временных промежутков
            'selectMirror' => false, // Отключаем отображение выбранного времени
            'dayMaxEvents' => true, // Показывать "еще" если событий много
            'weekends' => true, // Показывать выходные дни
            'locale' => 'ru', // Русская локализация
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список'
            ],
            'allDaySlot' => false, // Не показывать слот "Весь день"
            'slotMinTime' => '08:00:00', // Начало рабочего дня
            'slotMaxTime' => '20:00:00', // Конец рабочего дня
            'slotDuration' => '00:15:00', // Длительность слота 15 минут
            'snapDuration' => '00:05:00', // Привязка к 5-минутным интервалам
            'slotLabelFormat' => [ // Формат отображения времени в слотах
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false, // 24-часовой формат
            ],
            'eventDidMount' => 'function(info) {
                try {
                    console.log("=== BID CALENDAR EVENT ===");
                    console.log("СОБЫТИЕ МОНТИРУЕТСЯ:", info.event.id);
                    console.log("Application ID:", info.event.extendedProps.application_id);
                    console.log("Full extendedProps:", info.event.extendedProps);
                    console.log("========================");
                } catch (e) {
                    console.error("Ошибка в eventDidMount:", e);
                }
            }',
        ];
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
        $user = auth()->user();
        
        // Если пользователь не аутентифицирован, возвращаем пустой массив
        if (!$user) {
            return [];
        }
        
        // Если нет данных формы для фильтрации, возвращаем пустой массив
        // Проверяем наличие хотя бы одного поля для фильтрации
        $hasFilterData = !empty($this->formData['city_id']) || 
                        !empty($this->formData['clinic_id']) || 
                        !empty($this->formData['branch_id']) || 
                        !empty($this->formData['doctor_id']) || 
                        !empty($this->formData['cabinet_id']);
        
        if (!$hasFilterData) {
            return [];
        }
        
        // Добавляем уникальный идентификатор для принудительного обновления
        $fetchInfo['_timestamp'] = time();
        $fetchInfo['_random'] = uniqid();
        $fetchInfo['_cache_buster'] = md5(time() . rand());
        
        // Добавляем заголовки для предотвращения кэширования
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Создаем фильтры на основе данных формы
        $filters = $this->createFiltersFromFormData();
        
        // Используем сервис для генерации событий
        $events = $this->getEventService()->generateEvents($fetchInfo, $filters, $user);
        
        return $events;
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
            'clinic_ids' => [],
            'branch_ids' => [],
            'doctor_ids' => [],
            'date_from' => null,
            'date_to' => null,
        ];

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
     * Передает данные слота в форму для заполнения поля appointment_datetime
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
        
        // Заполняем массив данными для формы
        $this->slotData = [
            'city_id' => $shift->cabinet->branch->city_id,
            'city_name' => $shift->cabinet->branch->city->name,
            'clinic_id' => $shift->cabinet->branch->clinic_id,
            'clinic_name' => $shift->cabinet->branch->clinic->name,
            'branch_id' => $shift->cabinet->branch_id,
            'branch_name' => $shift->cabinet->branch->name,
            'cabinet_id' => $shift->cabinet_id,
            'cabinet_name' => $shift->cabinet->name,
            'doctor_id' => $shift->doctor_id,
            'doctor_name' => $shift->doctor->full_name,
            'appointment_datetime' => $slotStart,
        ];

        // Отправляем событие с данными слота через Livewire
        $this->dispatch('slotSelected', $this->slotData);
        
        Notification::make()
            ->title('Время выбрано')
            ->body('Время приема выбрано из календаря')
            ->success()
            ->send();
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
