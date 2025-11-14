<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use JsonException;

class AlbatoNotifier extends AbstractHttpNotifier
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function send(Application $application, array $settings): CrmNotificationResult
    {
        $hook = Arr::get($settings, 'webhook_url');
        $secret = Arr::get($settings, 'secret');

        if (! $hook || ! $secret) {
            return new CrmNotificationResult(false, error: 'Не заданы обязательные настройки Albato (webhook_url или secret).');
        }

        $timezone = config('app.timezone');
        $appointmentDateTime = $application->appointment_datetime?->copy()->setTimezone($timezone);
        $createdAt = $application->created_at?->copy()->setTimezone($timezone);
        $updatedAt = $application->updated_at?->copy()->setTimezone($timezone);

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
            'promo_code' => $application->promo_code,
            'appointment_datetime' => $appointmentDateTime?->format(self::DATE_FORMAT),
            'created_at' => $createdAt?->format(self::DATE_FORMAT),
            'updated_at' => $updatedAt?->format(self::DATE_FORMAT),
            'source' => $application->source,
            'appointment_status' => $application->appointment_status,
            'integration_type' => $application->integration_type,
        ];

        try {
            $jsonPayload = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            Log::error('Albato payload encoding failed.', [
                'application_id' => $application->id,
                'message' => $exception->getMessage(),
            ]);

            return new CrmNotificationResult(false, error: 'Не удалось подготовить данные для Albato.');
        }

        $signature = hash_hmac('sha256', $jsonPayload, $secret);

        try {
            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Hub-Signature-256' => 'sha256='.$signature,
                ])
                ->withBody($jsonPayload, 'application/json')
                ->post($hook)
                ->throw();

            return new CrmNotificationResult(true, $response->json());
        } catch (RequestException $exception) {
            $response = $exception->response;

            Log::error('Albato request failed.', [
                'application_id' => $application->id,
                'hook' => $hook,
                'status' => $response?->status(),
                'response_body' => $response?->body(),
                'message' => $exception->getMessage(),
            ]);

            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }
}
