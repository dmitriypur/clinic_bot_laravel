<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Filament\Resources\AppointmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Обновляем календарь после изменения статуса приема
        $this->dispatch('refetchEvents');
    }
    
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Загружаем данные заявки при редактировании
        if (isset($data['application_id'])) {
            $application = \App\Models\Application::with(['city', 'clinic', 'branch', 'doctor', 'cabinet'])->find($data['application_id']);
            if ($application) {
                $data['patient_info'] = [
                    'full_name' => $application->full_name,
                    'full_name_parent' => $application->full_name_parent,
                    'birth_date' => $application->birth_date?->format('d.m.Y'),
                    'phone' => $application->phone,
                ];
                $data['appointment_info'] = [
                    'datetime' => $application->appointment_datetime?->format('d.m.Y H:i'),
                    'city' => $application->city?->name,
                    'clinic' => $application->clinic?->name,
                    'branch' => $application->branch?->name,
                    'doctor' => $application->doctor?->name,
                    'cabinet' => $application->cabinet?->name,
                ];
                
                // Заполняем поля только для чтения
                $data['patient_full_name'] = $application->full_name;
                $data['patient_parent'] = $application->full_name_parent;
                $data['patient_birth_date'] = $application->birth_date?->format('d.m.Y');
                $data['patient_phone'] = $application->phone;
                $data['appointment_datetime'] = $application->appointment_datetime?->format('d.m.Y H:i');
                $data['appointment_city'] = $application->city?->name;
                $data['appointment_clinic'] = $application->clinic?->name;
                $data['appointment_branch'] = $application->branch?->name;
                $data['appointment_doctor'] = $application->doctor?->name;
                $data['appointment_cabinet'] = $application->cabinet?->name;
            }
        }
        
        return $data;
    }
}
