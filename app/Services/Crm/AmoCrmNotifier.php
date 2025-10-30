<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class AmoCrmNotifier extends AbstractHttpNotifier
{
    public function send(Application $application, array $settings): CrmNotificationResult
    {
        $hook = Arr::get($settings, 'webhook_url');
        $token = Arr::get($settings, 'token');

        if (!$hook || !$token) {
            return new CrmNotificationResult(false, error: 'Не указаны webhook_url или token для AmoCRM.');
        }

        $payload = [
            'name' => Arr::get($settings, 'lead_prefix', 'Заявка') . ' #' . $application->id,
            'price' => Arr::get($settings, 'price', 0),
            'status_id' => Arr::get($settings, 'status_id'),
            'custom_fields_values' => [
                [
                    'field_name' => 'Телефон',
                    'values' => [[
                        'value' => $application->phone,
                    ]],
                ],
            ],
            'patient' => [
                'name' => $application->full_name,
                'birth_date' => $application->birth_date,
            ],
            'appointment_datetime' => optional($application->appointment_datetime)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];

        try {
            $response = $this->http
                ->withToken($token)
                ->post($hook, $payload)
                ->throw();

            return new CrmNotificationResult(true, $response->json());
        } catch (RequestException $exception) {
            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }
}
