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

        if (! $endpoint || ! $token) {
            return new CrmNotificationResult(false, error: 'Не указаны webhook_url или token для 1С.');
        }

        $payload = [
            'tgid' => (string) ($application->tg_user_id ?? ''),
            'chatid' => (string) ($application->tg_chat_id ?? ''),
            'promocode' => (string) ($application->promo_code ?? ''),
            'fullname' => (string) ($application->full_name ?? ''),
            'birthday' => (string) ($application->birth_date ?? ''),
            'phone' => (string) ($application->phone ?? ''),
            'city' => (string) ($application->city?->name ?? ''),
            'items' => (object) [],
        ];

        try {
            $response = $this->http
                ->withHeaders(['X-LO-Token' => $token])
                ->post($endpoint, $payload)
                ->throw();

            return new CrmNotificationResult(true, $response->json());
        } catch (RequestException $exception) {
            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }
}
