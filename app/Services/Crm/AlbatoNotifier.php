<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class AlbatoNotifier extends AbstractHttpNotifier
{
    public function send(Application $application, array $settings): CrmNotificationResult
    {
        $hook = Arr::get($settings, 'webhook_url');

        if (!$hook) {
            return new CrmNotificationResult(false, error: 'Не указан webhook_url для Albato.');
        }

        $appointmentDateTime = $application->appointment_datetime
            ? $application->appointment_datetime->copy()->setTimezone(config('app.timezone'))
            : null;

        $payload = [
            'id' => $application->id,
            'clinic' => $application->clinic?->name,
            'branch' => $application->branch?->name,
            'doctor' => $application->doctor?->full_name,
            'patient' => [
                'name' => $application->full_name,
                'phone' => $application->phone,
                'birth_date' => $application->birth_date,
            ],
            'appointment_datetime' => $appointmentDateTime?->format('Y-m-d H:i:s'),
        ];

        try {
            $response = $this->http->post($hook, $payload)->throw();

            return new CrmNotificationResult(true, $response->json());
        } catch (RequestException $exception) {
            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }
}
