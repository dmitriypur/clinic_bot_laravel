<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\IntegrationEndpoint;

class ApplicationRoutingService
{
    public function shouldSendToOneC(
        ?Branch $branch,
        ?Clinic $clinic,
        mixed $appointmentDateTime,
        ?string $slotExternalId = null
    ): bool {
        if (! $this->hasActiveOneCIntegration($branch, $clinic)) {
            return false;
        }

        return $this->hasAppointmentDetails($appointmentDateTime, $slotExternalId);
    }

    public function shouldRequireOneCSlot(?Branch $branch, mixed $appointmentDateTime): bool
    {
        if (! $branch || ! $this->hasActiveOneCIntegration($branch, $branch->clinic)) {
            return false;
        }

        if (! $branch->isOnecPushMode()) {
            return false;
        }

        return $this->hasAppointmentDetails($appointmentDateTime);
    }

    public function shouldDispatchCrm(Application $application): bool
    {
        $clinic = $application->clinic;

        if (! $clinic && $application->clinic_id) {
            $clinic = Clinic::query()->find($application->clinic_id);
        }

        if (! $this->hasCrmIntegration($clinic)) {
            return false;
        }

        $branch = $application->branch;

        if (! $branch && $application->branch_id) {
            $branch = Branch::with(['clinic', 'integrationEndpoint'])->find($application->branch_id);
        }

        return ! $this->shouldSendToOneC(
            branch: $branch,
            clinic: $clinic,
            appointmentDateTime: $application->appointment_datetime,
        );
    }

    public function hasCrmIntegration(?Clinic $clinic): bool
    {
        if (! $clinic) {
            return false;
        }

        return filled($clinic->crm_provider) && $clinic->crm_provider !== 'none';
    }

    public function hasAppointmentDetails(mixed $appointmentDateTime, ?string $slotExternalId = null): bool
    {
        return filled($appointmentDateTime) || filled($slotExternalId);
    }

    private function hasActiveOneCIntegration(?Branch $branch, ?Clinic $clinic): bool
    {
        $endpoint = $branch?->integrationEndpoint;

        if ($endpoint) {
            return $endpoint->type === IntegrationEndpoint::TYPE_ONEC
                && $endpoint->is_active;
        }

        if (! $branch && $clinic) {
            return false;
        }

        return false;
    }
}
