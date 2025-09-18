<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'status',
        'role',
        'clinic_id',
        'doctor_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'integer',
            'clinic_id' => 'integer',
            'doctor_id' => 'integer',
        ];
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Определяет, может ли пользователь получить доступ к панели Filament
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Разрешаем доступ пользователям с ролью super_admin, partner или doctor
        return $this->hasRole(['super_admin', 'partner', 'doctor']);
    }
    
    /**
     * Проверяет, является ли пользователь супер-администратором
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }
    
    /**
     * Проверяет, является ли пользователь партнером
     */
    public function isPartner(): bool
    {
        return $this->hasRole('partner');
    }
    
    /**
     * Проверяет, является ли пользователь врачом
     */
    public function isDoctor(): bool
    {
        return $this->hasRole('doctor');
    }
    
    /**
     * Получает кабинеты, к которым имеет доступ пользователь
     */
    public function getAccessibleCabinets()
    {
        if ($this->isSuperAdmin()) {
            return \App\Models\Cabinet::all();
        }
        
        if ($this->isPartner()) {
            return \App\Models\Cabinet::whereHas('branch', function($query) {
                $query->where('clinic_id', $this->clinic_id);
            })->get();
        }
        
        if ($this->isDoctor()) {
            return \App\Models\Cabinet::whereHas('branch.doctors', function($query) {
                $query->where('doctor_id', $this->doctor_id);
            })->get();
        }
        
        return collect();
    }
    
    /**
     * Получает смены, к которым имеет доступ пользователь
     */
    public function getAccessibleShifts()
    {
        if ($this->isSuperAdmin()) {
            return \App\Models\DoctorShift::all();
        }
        
        if ($this->isPartner()) {
            return \App\Models\DoctorShift::whereHas('cabinet.branch', function($query) {
                $query->where('clinic_id', $this->clinic_id);
            })->get();
        }
        
        if ($this->isDoctor()) {
            return \App\Models\DoctorShift::where('doctor_id', $this->doctor_id)->get();
        }
        
        return collect();
    }

}
