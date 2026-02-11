<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class Bitrix24Notifier extends AbstractHttpNotifier
{
    public function send(Application $application, array $settings): CrmNotificationResult
    {
        $baseUrl = $this->normalizeBaseUrl(Arr::get($settings, 'webhook_url'));

        if (! $baseUrl) {
            return new CrmNotificationResult(false, error: 'Не указан корректный webhook_url для Bitrix24.');
        }

        $appointmentDateTime = $application->appointment_datetime
            ? $application->appointment_datetime->copy()->setTimezone(config('app.timezone'))
            : null;

        $comment = sprintf(
            "Дата приема: %s\nКлиника: %s\nФилиал: %s\nВрач: %s",
            $appointmentDateTime?->format('d.m.Y H:i') ?? '—',
            $application->clinic?->name ?? '—',
            $application->branch?->name ?? '—',
            $application->doctor?->full_name ?? '—'
        );

        $contactId = $this->resolveContactId($baseUrl, $application, $comment);

        if (! $contactId) {
            return new CrmNotificationResult(false, error: 'Не удалось создать контакт в Bitrix24.');
        }

        $dealPayload = [
            'fields' => [
                'TITLE' => Arr::get($settings, 'title_prefix', 'Заявка').' #'.$application->id,
                'CONTACT_ID' => $contactId,
                'COMMENTS' => $comment,
            ],
        ];

        if ($stage = Arr::get($settings, 'stage_id')) {
            $dealPayload['fields']['STAGE_ID'] = $stage;
        }

        if ($category = Arr::get($settings, 'category_id')) {
            $dealPayload['fields']['CATEGORY_ID'] = $category;
        }

        try {
            $response = $this->callMethod($baseUrl, 'crm.deal.add', $dealPayload);

            return new CrmNotificationResult(true, $response);
        } catch (RequestException $exception) {
            return new CrmNotificationResult(false, error: $exception->getMessage());
        }
    }

    protected function resolveContactId(string $baseUrl, Application $application, string $comment): ?int
    {
        $phone = $application->phone;

        if ($phone) {
            try {
                $listResponse = $this->callMethod($baseUrl, 'crm.contact.list', [
                    'filter' => [
                        'PHONE' => $phone,
                    ],
                    'select' => ['ID'],
                ]);

                $existing = Arr::get($listResponse, 'result.0.ID');

                if ($existing) {
                    return (int) $existing;
                }
            } catch (RequestException $exception) {
                // Продолжим попытку создать контакт.
            }
        }

        try {
            $createResponse = $this->callMethod($baseUrl, 'crm.contact.add', [
                'fields' => [
                    'NAME' => $application->full_name ?: 'Пациент',
                    'OPENED' => 'Y',
                    'COMMENTS' => $comment,
                    'PHONE' => $phone ? [[
                        'VALUE' => $phone,
                        'VALUE_TYPE' => 'WORK',
                    ]] : null,
                ],
            ]);

            return (int) Arr::get($createResponse, 'result');
        } catch (RequestException $exception) {
            return null;
        }
    }

    protected function callMethod(string $baseUrl, string $method, array $payload): array
    {
        $url = $this->makeUrl($baseUrl, $method);

        $response = $this->http->post($url, $payload)->throw();

        return $response->json() ?? [];
    }

    protected function makeUrl(string $baseUrl, string $method): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        if (! str_ends_with($method, '.json')) {
            $method .= '.json';
        }

        return $baseUrl.'/'.$method;
    }

    protected function normalizeBaseUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = rtrim($url, '/');

        if (preg_match('#(.*)/(crm\.[^/]+)$#', $url, $matches)) {
            return $matches[1];
        }

        return $url;
    }
}
