<?php

namespace App\Policies;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Политика авторизации для кабинетов
 * 
 * Определяет права доступа к кабинетам для разных ролей пользователей:
 * - super_admin: полный доступ ко всем кабинетам
 * - partner: доступ только к кабинетам филиалов своих клиник
 * - doctor: доступ только к кабинетам филиалов где работает
 */
class CabinetPolicy
{
    /**
     * Проверка права просмотра списка кабинетов
     * Super admin, partner и doctor могут просматривать списки кабинетов
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner', 'doctor']);
    }

    /**
     * Проверка права просмотра конкретного кабинета
     * Super admin видит все, partner - только кабинеты своих клиник, doctor - только кабинеты филиалов где работает
     */
    public function view(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin видит все
        }
        
        if ($user->isPartner()) {
            // Partner видит только кабинеты филиалов своих клиник
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        if ($user->isDoctor()) {
            // Doctor видит только кабинеты филиалов где работает
            return $cabinet->branch->doctors()->where('doctor_id', $user->doctor_id)->exists();
        }
        
        return false;
    }

    /**
     * Проверка права создания кабинетов
     * Только super admin и partner могут создавать кабинеты
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner']);
    }

    /**
     * Проверка права редактирования кабинета
     * Super admin может редактировать все, partner - только кабинеты своих клиник
     */
    public function update(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может редактировать все
        }
        
        if ($user->isPartner()) {
            // Partner может редактировать только кабинеты филиалов своих клиник
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Проверка права удаления кабинета
     * Super admin может удалять все, partner - только кабинеты своих клиник
     */
    public function delete(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может удалять все
        }
        
        if ($user->isPartner()) {
            // Partner может удалять только кабинеты филиалов своих клиник
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Проверка права восстановления кабинета (soft delete)
     * Super admin может восстанавливать все, partner - только кабинеты своих клиник
     */
    public function restore(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может восстанавливать все
        }
        
        if ($user->isPartner()) {
            // Partner может восстанавливать только кабинеты филиалов своих клиник
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Проверка права окончательного удаления кабинета (force delete)
     * Super admin может окончательно удалять все, partner - только кабинеты своих клиник
     */
    public function forceDelete(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;  // Super admin может окончательно удалять все
        }
        
        if ($user->isPartner()) {
            // Partner может окончательно удалять только кабинеты филиалов своих клиник
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }
}
