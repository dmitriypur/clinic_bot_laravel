<?php

namespace App\Services\Crm;

use App\Jobs\SendCrmNotificationJob;
use App\Models\Application;

class CrmNotificationService
{
    public function dispatch(Application $application): void
    {
        $clinic = $application->clinic;

        if (!$clinic) {
            return;
        }

        if ($clinic->crm_provider === 'none' || empty($clinic->crm_provider)) {
            return;
        }

        SendCrmNotificationJob::dispatch($application->id);
    }
}
