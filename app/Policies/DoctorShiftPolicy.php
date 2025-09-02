<?php

namespace App\Policies;

use App\Models\DoctorShift;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Политика авторизации для смен врачей
 * 
 * Определяет права доступа к сменам врачей для разных ролей пользователей:
 * - super_admin: полный доступ ко всем сменам
 * - partner: доступ только к сменам в кабинетах филиалов своих клиник
 * - doctor: доступ только к своим сменам (только просмотр)
 */
class DoctorShiftPolicy
{
    /**
     * Проверка права просмотра списка смен
     * Super admin, partner и doctor могут просматривать списки смен
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner', 'doctor']);
    }

    /**
     * Проверка права просмотра конкретной смены
     * Super admin видит все, partner - только смены в кабинетах своих клиник, doctor - только свои смены
     */
    public function view(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin видит все смены
        }
        
        if ($user->isPartner()) {
            // Partner видит только смены в кабинетах филиалов своих клиник
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        if ($user->isDoctor()) {
            // Doctor видит только свои смены
            return $doctorShift->doctor_id === $user->doctor_id;
        }
        
        return false;
    }

    /**
     * Проверка права создания смен
     * Только super admin и partner могут создавать смены
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner']);
    }

    /**
     * Проверка права редактирования смены
     * Super admin может редактировать все, partner - только смены в кабинетах своих клиник
     * Врачи не могут редактировать смены
     */
    public function update(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может редактировать все
        }
        
        if ($user->isPartner()) {
            // Partner может редактировать только смены в кабинетах филиалов своих клиник
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может редактировать смены
        return false;
    }

    /**
     * Проверка права удаления смены
     * Super admin может удалять все, partner - только смены в кабинетах своих клиник
     * Врачи не могут удалять смены
     */
    public function delete(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может удалять все
        }
        
        if ($user->isPartner()) {
            // Partner может удалять только смены в кабинетах филиалов своих клиник
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может удалять смены
        return false;
    }

    /**
     * Проверка права восстановления смены (soft delete)
     * Super admin может восстанавливать все, partner - только смены в кабинетах своих клиник
     * Врачи не могут восстанавливать смены
     */
    public function restore(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может восстанавливать все
        }
        
        if ($user->isPartner()) {
            // Partner может восстанавливать только смены в кабинетах филиалов своих клиник
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может восстанавливать смены
        return false;
    }

    /**
     * Проверка права окончательного удаления смены (force delete)
     * Super admin может окончательно удалять все, partner - только смены в кабинетах своих клиник
     * Врачи не могут окончательно удалять смены
     */
    public function forceDelete(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может окончательно удалять все
        }
        
        if ($user->isPartner()) {
            // Partner может окончательно удалять только смены в кабинетах филиалов своих клиник
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может окончательно удалять смены
        return false;
    }
}
