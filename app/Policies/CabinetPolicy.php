<?php

namespace App\Policies;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CabinetPolicy
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
    public function view(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        if ($user->isDoctor()) {
            return $cabinet->branch->doctors()->where('doctor_id', $user->doctor_id)->exists();
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
    public function update(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Cabinet $cabinet): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        if ($user->isPartner()) {
            return $cabinet->branch->clinic_id === $user->clinic_id;
        }
        
        return false;
    }
}
