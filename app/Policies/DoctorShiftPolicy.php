<?php

namespace App\Policies;

use App\Models\DoctorShift;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DoctorShiftPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner', 'doctor']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        if ($user->isDoctor()) {
            return $doctorShift->doctor_id === $user->doctor_id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'partner']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может редактировать смены
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может удалять смены
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может восстанавливать смены
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DoctorShift $doctorShift): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $doctorShift->cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        // Врач не может окончательно удалять смены
        return false;
    }
}
