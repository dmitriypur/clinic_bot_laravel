<?php

namespace App\Filament\Resources\ApplicationResource\Widgets;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
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
 * Виджет календаря записи пациентов
 * 
 * Отображает календарь с доступными слотами для записи пациентов.
 * Позволяет создавать записи на прием с выбором времени из доступных слотов.
 */
class AppointmentCalendarWidget extends FullCalendarWidget
{
    public \Illuminate\Database\Eloquent\Model | string | null $model = Application::class;
    
    public array $slotData = [];
    
    protected $listeners = ['refetchEvents'];

    /**
     * Конфигурация календаря
     */
    public function config(): array
    {
        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
            ],
            'initialView' => 'timeGridWeek',
            'navLinks' => true,
            'editable' => true,
            'selectable' => true,
            'selectMirror' => true,
            'dayMaxEvents' => true,
            'weekends' => true,
            'locale' => 'ru',
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список'
            ],
            'allDaySlot' => false,
            'slotMinTime' => '08:00:00',
            'slotMaxTime' => '20:00:00',
            'slotDuration' => '00:15:00', // 15 минут для отображения всех возможных слотов
            'snapDuration' => '00:15:00',
            'slotLabelFormat' => [            // Формат отображения времени в слотах
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,            // 24-часовой формат
            ],




        ];
    }

    /**
     * Получить события для календаря
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $events = [];

        // Получаем все смены врачей в указанном диапазоне
        $shifts = DoctorShift::query()
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor', 'cabinet.branch.clinic', 'cabinet.branch.city'])
            ->get();

        foreach ($shifts as $shift) {
            // Получаем длительность слота для этой смены
            $slotDuration = $shift->getEffectiveSlotDuration();
            
            // Генерируем слоты для смены
            $slots = $shift->getTimeSlots();
            
            foreach ($slots as $slot) {
                // Проверяем, занят ли слот
                $isOccupied = $this->isSlotOccupied($shift->cabinet_id, $slot['start']);
                
                // Получаем информацию о заявке, если слот занят
                $application = null;
                if ($isOccupied) {
                    $application = Application::query()
                        ->where('cabinet_id', $shift->cabinet_id)
                        ->where('appointment_datetime', $slot['start'])
                        ->first();
                }
                
                $events[] = [
                    'id' => 'slot_' . $shift->id . '_' . $slot['start']->format('Y-m-d_H-i'),
                    'title' => $isOccupied ? ($application ? $application->full_name : 'Занят') : 'Свободен',
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'backgroundColor' => $isOccupied ? '#dc2626' : '#10b981', // Красный для занятых, зеленый для свободных
                    'borderColor' => $isOccupied ? '#dc2626' : '#10b981',
                    'extendedProps' => [
                        'shift_id' => $shift->id,
                        'cabinet_id' => $shift->cabinet_id,
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                        'clinic_name' => $shift->cabinet->branch->clinic->name ?? 'Клиника не указана',
                        'is_occupied' => $isOccupied,
                        'slot_start' => $slot['start'],
                        'slot_end' => $slot['end'],
                        'application_id' => $application ? $application->id : null,
                    ]
                ];
            }
        }

        return $events;
    }

    /**
     * Проверить, занят ли слот
     */
    private function isSlotOccupied(int $cabinetId, Carbon $slotStart): bool
    {
        // Проверяем, есть ли запись в этом кабинете на это время
        return Application::query()
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStart)
            ->exists();
    }

    /**
     * Обработка клика по событию
     */
    public function onEventClick(array $data): void
    {
        $event = $data;
        $extendedProps = $event['extendedProps'] ?? [];
        
        // Если слот занят - открываем форму редактирования
        if (isset($extendedProps['is_occupied']) && $extendedProps['is_occupied']) {
            $this->onOccupiedSlotClick($extendedProps);
            return;
        }

        // Если слот свободен - открываем форму создания
        $shift = DoctorShift::with(['cabinet.branch.clinic', 'cabinet.branch.city', 'doctor'])
            ->find($extendedProps['shift_id']);

        if (!$shift) {
            Notification::make()
                ->title('Ошибка')
                ->body('Смена врача не найдена')
                ->danger()
                ->send();
            return;
        }

        // Сохраняем данные слота в свойстве виджета
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
            'appointment_datetime' => $extendedProps['slot_start'],
        ];

        // Открываем форму для создания записи
        $this->mountAction('createAppointment');
    }

    /**
     * Обработка клика по занятому слоту
     */
    public function onOccupiedSlotClick(array $data): void
    {
        $extendedProps = $data;
        
        // Находим заявку по кабинету и времени
        $slotStart = $extendedProps['slot_start'];
        if (is_string($slotStart)) {
            $slotStart = \Carbon\Carbon::parse($slotStart);
        }
        
        $application = Application::query()
            ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
            ->where('cabinet_id', $extendedProps['cabinet_id'])
            ->where('appointment_datetime', $slotStart)
            ->first();

        if (!$application) {
            Notification::make()
                ->title('Ошибка')
                ->body('Заявка не найдена')
                ->danger()
                ->send();
            return;
        }

        // Заполняем данные для формы
        $this->slotData = [
            'application_id' => $application->id,
            'city_id' => $application->city_id,
            'city_name' => $application->city->name,
            'clinic_id' => $application->clinic_id,
            'clinic_name' => $application->clinic->name,
            'branch_id' => $application->branch_id,
            'branch_name' => $application->branch->name,
            'cabinet_id' => $application->cabinet_id,
            'cabinet_name' => $application->cabinet->name,
            'doctor_id' => $application->doctor_id,
            'doctor_name' => $application->doctor->full_name,
            'appointment_datetime' => $application->appointment_datetime,
            'full_name' => $application->full_name,
            'phone' => $application->phone,
            'full_name_parent' => $application->full_name_parent,
            'birth_date' => $application->birth_date,
            'promo_code' => $application->promo_code,
        ];

        // Открываем форму редактирования
        $this->mountAction('editAppointment');
    }

    /**
     * Действия виджета
     */
    protected function headerActions(): array
    {
        return [
            \Filament\Actions\Action::make('createAppointment')
                ->label('Создать запись')
                ->icon('heroicon-o-plus')
                ->form([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('city_name')
                                ->label('Город')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['city_name'] ?? ''),
                            
                            TextInput::make('clinic_name')
                                ->label('Клиника')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['clinic_name'] ?? ''),
                            
                            TextInput::make('branch_name')
                                ->label('Филиал')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['branch_name'] ?? ''),
                            
                            TextInput::make('cabinet_name')
                                ->label('Кабинет')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['cabinet_name'] ?? ''),
                            
                            TextInput::make('doctor_name')
                                ->label('Врач')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['doctor_name'] ?? ''),
                            
                            DateTimePicker::make('appointment_datetime')
                                ->label('Дата и время приема')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['appointment_datetime'] ?? null),
                            
                            // Скрытые поля для сохранения
                            TextInput::make('city_id')
                                ->hidden()
                                ->required()
                                ->default(fn() => $this->slotData['city_id'] ?? null),
                            TextInput::make('clinic_id')
                                ->hidden()
                                ->required()
                                ->default(fn() => $this->slotData['clinic_id'] ?? null),
                            TextInput::make('branch_id')
                                ->hidden()
                                ->required()
                                ->default(fn() => $this->slotData['branch_id'] ?? null),
                            TextInput::make('cabinet_id')
                                ->hidden()
                                ->required()
                                ->default(fn() => $this->slotData['cabinet_id'] ?? null),
                            TextInput::make('doctor_id')
                                ->hidden()
                                ->required()
                                ->default(fn() => $this->slotData['doctor_id'] ?? null),
                        ]),
                    
                    Grid::make(2)
                        ->schema([
                            TextInput::make('full_name_parent')
                                ->label('ФИО родителя'),
                            
                            TextInput::make('full_name')
                                ->label('ФИО ребенка')
                                ->required(),
                            
                            TextInput::make('birth_date')
                                ->label('Дата рождения')
                                ->type('date'),
                            
                            TextInput::make('phone')
                                ->label('Телефон')
                                ->tel()
                                ->required(),
                            
                            TextInput::make('promo_code')
                                ->label('Промокод'),
                        ]),
                ])
                ->action(function (array $data) {
                    // Создаем запись используя данные из slotData
                    $applicationData = [
                        'city_id' => $this->slotData['city_id'],
                        'clinic_id' => $this->slotData['clinic_id'],
                        'branch_id' => $this->slotData['branch_id'],
                        'cabinet_id' => $this->slotData['cabinet_id'],
                        'doctor_id' => $this->slotData['doctor_id'],
                        'appointment_datetime' => $this->slotData['appointment_datetime'],
                        'full_name' => $data['full_name'],
                        'phone' => $data['phone'],
                        'full_name_parent' => $data['full_name_parent'] ?? null,
                        'birth_date' => $data['birth_date'] ?? null,
                        'promo_code' => $data['promo_code'] ?? null,
                    ];
                    
                    Application::create($applicationData);
                    
                    Notification::make()
                        ->title('Успешно')
                        ->body('Запись на прием создана')
                        ->success()
                        ->send();
                    
                    // Обновляем календарь
                    $this->refreshRecords();
                    

                }),
                
            \Filament\Actions\Action::make('editAppointment')
                ->label('Редактировать запись')
                ->icon('heroicon-o-pencil')
                ->form([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('city_name')
                                ->label('Город')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['city_name'] ?? ''),
                            
                            TextInput::make('clinic_name')
                                ->label('Клиника')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['clinic_name'] ?? ''),
                            
                            TextInput::make('branch_name')
                                ->label('Филиал')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['branch_name'] ?? ''),
                            
                            TextInput::make('cabinet_name')
                                ->label('Кабинет')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['cabinet_name'] ?? ''),
                            
                            TextInput::make('doctor_name')
                                ->label('Врач')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['doctor_name'] ?? ''),
                            
                            DateTimePicker::make('appointment_datetime')
                                ->label('Дата и время приема')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(fn() => $this->slotData['appointment_datetime'] ?? null),
                            
                            // Скрытые поля для сохранения
                            TextInput::make('application_id')->hidden()->required()->default(fn() => $this->slotData['application_id'] ?? null),
                            TextInput::make('city_id')->hidden()->required()->default(fn() => $this->slotData['city_id'] ?? null),
                            TextInput::make('clinic_id')->hidden()->required()->default(fn() => $this->slotData['clinic_id'] ?? null),
                            TextInput::make('branch_id')->hidden()->required()->default(fn() => $this->slotData['branch_id'] ?? null),
                            TextInput::make('cabinet_id')->hidden()->required()->default(fn() => $this->slotData['cabinet_id'] ?? null),
                            TextInput::make('doctor_id')->hidden()->required()->default(fn() => $this->slotData['doctor_id'] ?? null),
                        ]),
                    
                    Grid::make(2)
                        ->schema([
                            TextInput::make('full_name_parent')
                                ->label('ФИО родителя'),
                            
                            TextInput::make('full_name')
                                ->label('ФИО ребенка')
                                ->required(),
                            
                            TextInput::make('birth_date')
                                ->label('Дата рождения')
                                ->type('date'),
                            
                            TextInput::make('phone')
                                ->label('Телефон')
                                ->tel()
                                ->required(),
                            
                            TextInput::make('promo_code')
                                ->label('Промокод'),
                        ]),
                ])
                ->action(function (array $data) {
                    // Получаем application_id из slotData
                    $applicationId = $this->slotData['application_id'] ?? null;
                    
                    if (!$applicationId) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('ID заявки не найден')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    // Находим заявку
                    $application = Application::find($applicationId);
                    
                    if (!$application) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Заявка не найдена')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    // Обновляем заявку
                    $application->update([
                        'full_name' => $data['full_name'],
                        'phone' => $data['phone'],
                        'full_name_parent' => $data['full_name_parent'] ?? null,
                        'birth_date' => $data['birth_date'] ?? null,
                        'promo_code' => $data['promo_code'] ?? null,
                    ]);
                    
                    Notification::make()
                        ->title('Успешно')
                        ->body('Заявка обновлена')
                        ->success()
                        ->send();
                    
                    // Обновляем календарь
                    $this->refreshRecords();
                    

                })
                ->extraModalFooterActions([
                    \Filament\Actions\Action::make('delete')
                        ->label('Удалить запись')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Удаление записи')
                        ->modalDescription('Вы уверены, что хотите удалить эту запись? Это действие нельзя отменить.')
                        ->action(function () {
                            // Получаем application_id из slotData
                            $applicationId = $this->slotData['application_id'] ?? null;
                            
                            if (!$applicationId) {
                                Notification::make()
                                    ->title('Ошибка')
                                    ->body('ID заявки не найден')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            
                            // Находим заявку
                            $application = Application::find($applicationId);
                            
                            if (!$application) {
                                Notification::make()
                                    ->title('Ошибка')
                                    ->body('Заявка не найдена')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            
                            // Удаляем заявку
                            $application->delete();
                            
                            Notification::make()
                                ->title('Успешно')
                                ->body('Заявка удалена')
                                ->success()
                                ->send();
                            
                            // Обновляем календарь
                            $this->refreshRecords();
                            

                        })
                ])
        ];
    }
    
    /**
     * Обработчик события обновления календаря
     */
    public function refetchEvents()
    {
        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }
}
