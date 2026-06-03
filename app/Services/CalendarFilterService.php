<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CalendarFilterService
{
    /**
     * Применяет фильтры к запросу смен врачей
     */
    public function applyShiftFilters(Builder $query, array $filters, User $user): Builder
    {
        // Базовые фильтры по датам
        if (! empty($filters['date_from'])) {
            $query->where('start_time', '>=', Carbon::parse($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->where('start_time', '<=', Carbon::parse($filters['date_to']));
        }

        // Фильтры по городам
        if (! empty($filters['city_ids'])) {
            $query->whereHas('cabinet.branch', function ($q) use ($filters) {
                $q->whereIn('city_id', $filters['city_ids']);
            });
        }

        // Фильтры по клиникам
        if (! empty($filters['clinic_ids'])) {
            $query->whereHas('cabinet.branch', function ($q) use ($filters) {
                $q->whereIn('clinic_id', $filters['clinic_ids']);
            });
        }

        // Фильтры по филиалам
        if (! empty($filters['branch_ids'])) {
            $query->whereHas('cabinet', function ($q) use ($filters) {
                $q->whereIn('branch_id', $filters['branch_ids']);
            });
        }

        // Фильтры по врачам
        if (! empty($filters['doctor_ids'])) {
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
        if (! empty($filters['date_from'])) {
            $query->where('appointment_datetime', '>=', Carbon::parse($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->where('appointment_datetime', '<=', Carbon::parse($filters['date_to']));
        }

        // Фильтры по клиникам
        if (! empty($filters['clinic_ids'])) {
            $query->whereIn('clinic_id', $filters['clinic_ids']);
        }

        // Фильтры по филиалам
        if (! empty($filters['branch_ids'])) {
            $query->whereIn('branch_id', $filters['branch_ids']);
        }

        // Фильтры по врачам
        if (! empty($filters['doctor_ids'])) {
            $query->whereIn('doctor_id', $filters['doctor_ids']);
        }

        // Фильтры по статусам
        if (! empty($filters['status_ids'])) {
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
            $query->whereHas('cabinet.branch', function ($q) use ($user) {
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
            $query->whereHas('branches.doctors', function ($q) use ($user) {
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
            $query->whereHas('doctors', function ($q) use ($user) {
                $q->where('branch_doctor.doctor_id', $user->doctor_id);
            });
        } elseif (! empty($clinicIds)) {
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
        if ($user->isDoctor()) {
            $doctor = Doctor::query()
                ->where('id', $user->doctor_id)
                ->first();

            return $doctor ? [$doctor->id => $doctor->full_name] : [];
        }

        $branchIds = collect($branchIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($branchIds === []) {
            return [];
        }

        $branches = Branch::query()
            ->with('clinic:id,integration_mode')
            ->whereIn('id', $branchIds)
            ->get(['id', 'clinic_id', 'integration_mode']);

        if ($branches->isEmpty()) {
            return [];
        }

        $onecBranchIds = $branches
            ->filter(fn (Branch $branch) => $branch->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $localBranchIds = $branches
            ->reject(fn (Branch $branch) => $branch->isOnecPushMode())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $doctorIds = collect();
        $now = now();

        if ($onecBranchIds !== []) {
            $doctorIds = $doctorIds->merge(
                OnecSlot::query()
                    ->whereIn('branch_id', $onecBranchIds)
                    ->where('status', OnecSlot::STATUS_FREE)
                    ->where('start_at', '>=', $now)
                    ->whereNotNull('doctor_id')
                    ->distinct()
                    ->pluck('doctor_id')
            );
        }

        if ($localBranchIds !== []) {
            $doctorIds = $doctorIds->merge(
                DoctorShift::query()
                    ->whereHas('cabinet', fn ($query) => $query->whereIn('branch_id', $localBranchIds))
                    ->where('end_time', '>=', $now)
                    ->distinct()
                    ->pluck('doctor_id')
            );
        }

        $doctorIds = $doctorIds
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($doctorIds === []) {
            return [];
        }

        $doctors = Doctor::query()
            ->whereIn('id', $doctorIds)
            ->where('status', 1)
            ->orderBy('last_name')
            ->get();

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
            $query->whereHas('branches', function ($q) use ($user) {
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

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
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

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            $dateFrom = Carbon::parse($filters['date_from']);
            $dateTo = Carbon::parse($filters['date_to']);

            if ($dateFrom->gt($dateTo)) {
                $errors[] = 'Дата начала не может быть позже даты окончания';
            }
        }

        return $errors;
    }
}
