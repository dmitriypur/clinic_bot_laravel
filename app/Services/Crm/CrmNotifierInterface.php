<?php

namespace App\Services\Crm;

use App\Models\Application;

interface CrmNotifierInterface
{
    /**
     * Отправляет заявку во внешнюю CRM.
     */
    public function send(Application $application, array $settings): CrmNotificationResult;
}
