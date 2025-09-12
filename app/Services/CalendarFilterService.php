<?php

namespace App\Services;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class CalendarFilterService
{
    /**
     * Применяет фильтры к запросу смен врачей
     */
    public function applyShiftFilters(Builder $query, array $filters, User $user): Builder
    {
        // Базовые фильтры по датам
        if (!empty($filters['date_from'])) {
            $query->where('start_time', '>=', Carbon::parse($filters['date_from']));
        }
        
        if (!empty($filters['date_to'])) {
            $query->where('start_time', '<=', Carbon::parse($filters['date_to']));
        }

        // Фильтры по клиникам
        if (!empty($filters['clinic_ids'])) {
            $query->whereHas('cabinet.branch', function($q) use ($filters) {
                $q->whereIn('clinic_id', $filters['clinic_ids']);
            });
        }
        
        // Фильтры по филиалам
        if (!empty($filters['branch_ids'])) {
            $query->whereHas('cabinet', function($q) use ($filters) {
                $q->whereIn('branch_id', $filters['branch_ids']);
            });
        }
        
        // Фильтры по врачам
        if (!empty($filters['doctor_ids'])) {
            $query->whereIn('doctor_id', $filters['doctor_ids']);
        }

        // Применяем фильтрацию по ролям
        $this->applyRoleFilters($query, $user);

        return $query;
    }

    /**
     * Применяет фильтры к запросу заявок
     */
    public function applyApplicationFilters(Builder $query, array $filters, User $user): Builder
    {
        // Фильтры по датам
        if (!empty($filters['date_from'])) {
            $query->where('appointment_datetime', '>=', Carbon::parse($filters['date_from']));
        }
        
        if (!empty($filters['date_to'])) {
            $query->where('appointment_datetime', '<=', Carbon::parse($filters['date_to']));
        }

        // Фильтры по клиникам
        if (!empty($filters['clinic_ids'])) {
            $query->whereIn('clinic_id', $filters['clinic_ids']);
        }
        
        // Фильтры по филиалам
        if (!empty($filters['branch_ids'])) {
            $query->whereIn('branch_id', $filters['branch_ids']);
        }
        
        // Фильтры по врачам
        if (!empty($filters['doctor_ids'])) {
            $query->whereIn('doctor_id', $filters['doctor_ids']);
        }
        
        // Фильтры по статусам
        if (!empty($filters['status_ids'])) {
            $query->whereIn('status_id', $filters['status_ids']);
        }

        // Применяем фильтрацию по ролям для заявок
        $this->applyApplicationRoleFilters($query, $user);

        return $query;
    }

    /**
     * Применяет фильтрацию по ролям пользователя для смен
     */
    private function applyRoleFilters(Builder $query, User $user): void
    {
        if ($user->isPartner()) {
            // Партнер видит только смены в кабинетах своих клиник
            $query->whereHas('cabinet.branch', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врач видит только свои смены
            $query->where('doctor_id', $user->doctor_id);
        }
        // super_admin видит все без ограничений
    }
    
    /**
     * Применяет фильтрацию по ролям пользователя для заявок
     */
    private function applyApplicationRoleFilters(Builder $query, User $user): void
    {
        if ($user->isPartner()) {
            // Партнер видит только заявки своей клиники
            $query->where('clinic_id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            // Врач видит только свои заявки
            $query->where('doctor_id', $user->doctor_id);
        }
        // super_admin видит все без ограничений
    }

    /**
     * Получает доступные клиники для пользователя
     */
    public function getAvailableClinics(User $user): array
    {
        $query = \App\Models\Clinic::query();
        
        if ($user->isPartner()) {
            $query->where('id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            // Врач видит только клиники, где он работает
            $query->whereHas('branches.doctors', function($q) use ($user) {
                $q->where('branch_doctor.doctor_id', $user->doctor_id);
            });
        }
        // super_admin видит все клиники
        
        return $query->pluck('name', 'id')->toArray();
    }

    /**
     * Получает доступные филиалы для пользователя
     */
    public function getAvailableBranches(User $user, ?array $clinicIds = null): array
    {
        $query = \App\Models\Branch::query();
        
        if ($user->isPartner()) {
            $query->where('clinic_id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            // Врач видит только филиалы, где он работает
            $query->whereHas('doctors', function($q) use ($user) {
                $q->where('branch_doctor.doctor_id', $user->doctor_id);
            });
        } elseif (!empty($clinicIds)) {
            $query->whereIn('clinic_id', $clinicIds);
        } else {
            // Если не указаны клиники и пользователь не партнер/врач, возвращаем пустой массив
            return [];
        }
        
        return $query->pluck('name', 'id')->toArray();
    }

    /**
     * Получает доступных врачей для пользователя
     */
    public function getAvailableDoctors(User $user, ?array $branchIds = null): array
    {
        $query = \App\Models\Doctor::query();
        
        if ($user->isDoctor()) {
            $query->where('id', $user->doctor_id);
        } elseif (!empty($branchIds)) {
            $query->whereHas('branches', function($q) use ($branchIds) {
                $q->whereIn('branch_doctor.branch_id', $branchIds);
            });
        } else {
            // Если не указаны филиалы и пользователь не врач, возвращаем пустой массив
            return [];
        }
        
        $doctors = $query->get();
        $result = [];
        
        foreach ($doctors as $doctor) {
            $result[$doctor->id] = $doctor->full_name;
        }
        
        return $result;
    }

    /**
     * Получает доступных врачей для фильтрации смен
     */
    public function getAvailableDoctorsForShifts(User $user): array
    {
        $query = \App\Models\Doctor::query();
        
        if ($user->isPartner()) {
            // Партнер видит врачей своих клиник
            $query->whereHas('branches', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врач видит только себя
            $query->where('id', $user->doctor_id);
        }
        // super_admin видит всех врачей
        
        $doctors = $query->get();
        $result = [];
        
        foreach ($doctors as $doctor) {
            $result[$doctor->id] = $doctor->full_name;
        }
        
        return $result;
    }

    /**
     * Валидирует фильтры для заявок
     */
    public function validateFilters(array $filters): array
    {
        $errors = [];
        
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $dateFrom = Carbon::parse($filters['date_from']);
            $dateTo = Carbon::parse($filters['date_to']);
            
            if ($dateFrom->gt($dateTo)) {
                $errors[] = 'Дата начала не может быть позже даты окончания';
            }
        }
        
        return $errors;
    }

    /**
     * Валидирует фильтры для смен врачей
     */
    public function validateShiftFilters(array $filters): array
    {
        $errors = [];
        
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $dateFrom = Carbon::parse($filters['date_from']);
            $dateTo = Carbon::parse($filters['date_to']);
            
            if ($dateFrom->gt($dateTo)) {
                $errors[] = 'Дата начала не может быть позже даты окончания';
            }
        }
        
        return $errors;
    }
}
