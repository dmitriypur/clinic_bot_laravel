<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Doctor;
use Filament\Widgets\Widget;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;

class CabinetScheduleWidget extends FullCalendarWidget
{
    public \Illuminate\Database\Eloquent\Model | string | null $model = DoctorShift::class;

    public function config(): array
    {
        return [
            'firstDay' => 1, // Понедельник
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
            'slotDuration' => '00:30:00',
            'snapDuration' => '00:15:00',
        ];
    }

    public function fetchEvents(array $fetchInfo): array
    {
        // Для тестирования используем жестко закодированный ID кабинета
        $cabinetId = 1;

        return DoctorShift::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor'])
            ->get()
            ->map(function (DoctorShift $shift) {
                return [
                    'id' => $shift->id,
                    'title' => $shift->doctor->full_name ?? 'Врач не назначен',
                    'start' => $shift->start_time,
                    'end' => $shift->end_time,
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'slot_duration' => $shift->slot_duration,
                    ]
                ];
            })
            ->toArray();
    }





    public function getFormSchema(): array
    {
        return [
            Select::make('doctor_id')
                ->label('Врач')
                ->required()
                ->searchable()
                ->options(function () {
                    // Для тестирования используем ID кабинета 1
                    $cabinetId = 1;
                    
                    $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                    
                    if (!$cabinet || !$cabinet->branch) {
                        return [];
                    }
                    
                    return $cabinet->branch->doctors->mapWithKeys(function ($doctor) {
                        return [$doctor->id => $doctor->full_name];
                    })->toArray();
                }),
            
            TextInput::make('slot_duration')
                ->label('Длительность слота (минуты)')
                ->numeric()
                ->default(30)
                ->required()
                ->minValue(15)
                ->maxValue(120),
            
            DateTimePicker::make('start_time')
                ->label('Начало смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
            
            DateTimePicker::make('end_time')
                ->label('Конец смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
        ];
    }



    protected function getShiftColor(DoctorShift $shift): string
    {
        // Цвета для разных врачей
        $colors = [
            '#3B82F6', // синий
            '#10B981', // зеленый
            '#F59E0B', // желтый
            '#EF4444', // красный
            '#8B5CF6', // фиолетовый
            '#06B6D4', // голубой
            '#84CC16', // лайм
            '#F97316', // оранжевый
        ];
        
        $doctorId = $shift->doctor_id ?? 0;
        return $colors[$doctorId % count($colors)];
    }

    protected function modalActions(): array
    {
        return [
            \Saade\FilamentFullCalendar\Actions\EditAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'doctor_id' => $this->record->doctor_id,
                        'slot_duration' => $this->record->slot_duration,
                        'start_time' => $this->record->start_time,
                        'end_time' => $this->record->end_time,
                    ]);
                })
                ->action(function (array $data) {
                    $this->record->update($data);
                    
                    Notification::make()
                        ->title('Смена обновлена')
                        ->body('Смена врача успешно обновлена')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
                
            \Saade\FilamentFullCalendar\Actions\DeleteAction::make()
                ->action(function () {
                    $this->record->delete();
                    
                    Notification::make()
                        ->title('Смена удалена')
                        ->body('Смена врача удалена из расписания')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
        ];
    }

    protected function headerActions(): array
    {
        return [
            \Saade\FilamentFullCalendar\Actions\CreateAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'start_time' => $arguments['start'] ?? null,
                        'end_time' => $arguments['end'] ?? null,
                        'slot_duration' => 30,
                    ]);
                })
                                ->action(function (array $data) {
                    // Для тестирования используем жестко закодированный ID кабинета
                    $cabinetId = 1;
                    $data['cabinet_id'] = $cabinetId;
                    
                    DoctorShift::create($data);
                    
                    Notification::make()
                        ->title('Смена создана')
                        ->body('Смена врача успешно добавлена в расписание')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
        ];
    }
}
