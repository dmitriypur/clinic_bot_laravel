<?php

namespace App\Models;

use App\Enums\IntegrationMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clinic extends Model
{
    protected $fillable = [
        'name',
        'status',
        'slot_duration',
        'external_id',
        'crm_provider',
        'crm_settings',
        'dashboard_calendar_enabled',
        'integration_mode',
    ];

    protected $casts = [
        'status' => 'integer',
        'slot_duration' => 'integer',
        'crm_settings' => 'array',
        'dashboard_calendar_enabled' => 'boolean',
        'integration_mode' => IntegrationMode::class,
    ];

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'clinic_city');
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'clinic_doctor');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function externalMappings(): HasMany
    {
        return $this->hasMany(ExternalMapping::class);
    }

    public function onecSlots(): HasMany
    {
        return $this->hasMany(OnecSlot::class);
    }

    public function crmIntegrationLogs(): HasMany
    {
        return $this->hasMany(CrmIntegrationLog::class);
    }

    public function getEffectiveSlotDuration(): int
    {
        return $this->slot_duration ?? 30;
    }

    public function integrationMode(): IntegrationMode
    {
        return $this->integration_mode ?? IntegrationMode::LOCAL;
    }

    public function isOnecPushMode(): bool
    {
        return $this->integrationMode() === IntegrationMode::ONEC_PUSH;
    }

    /**
     * @deprecated Используйте integrationMode()/isOnecPushMode()
     */
    public function usesOneCIntegration(): bool
    {
        return $this->isOnecPushMode();
    }
}
