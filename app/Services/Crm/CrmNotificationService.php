<?php

namespace App\Services\Crm;

use App\Jobs\SendCrmNotificationJob;
use App\Models\Application;
use App\Services\ApplicationRoutingService;

class CrmNotificationService
{
    public function __construct(private readonly ApplicationRoutingService $routingService) {}

    public function dispatch(Application $application): void
    {
        if (! $this->routingService->shouldDispatchCrm($application)) {
            return;
        }

        SendCrmNotificationJob::dispatch($application->id);
    }
}
