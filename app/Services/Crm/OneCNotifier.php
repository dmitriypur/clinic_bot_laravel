<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class OneCNotifier extends AbstractHttpNotifier
{
    public function send(Application $application, array $settings): CrmNotificationResult
    {
        $endpoint = Arr::get($settings, 'webhook_url');
        $token = Arr::get($settings, 'token');

        if (!$endpoint || !$token) {
            return new CrmNotificationResult(false, error: 'Не указаны webhook_url или token для 1С.');
        }

        $appointmentDateTime = $application->appointment_datetime
            ? $application->appointment_datetime->copy()->setTimezone(config('app.timezone'))
            : null;

        $payload = [
            'application_id' => $application->id,
            'clinic' => $application->clinic?->name,
            'branch' => $application->branch?->name,
            'doctor' => $application->doctor?->full_name,
            'appointment_datetime' => $appointmentDateTime?->format('Y-m-d H:i:s'),
            'patient' => [
                'full_name' => $application->full_name,
                'full_name_parent' => $application->full_name_parent,
                'birth_date' => $application->birth_date,
                'phone' => $application->phone,
            ],
        ];

        try {
            $response = $this->http
                ->withHeaders(['X-Auth-Token' => $token])
                ->post($endpoint, $payload)
                ->throw();

            return new CrmNotificationResult(true, $response->json());
        } catch (RequestException $exception) {
            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }
}
