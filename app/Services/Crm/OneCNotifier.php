<?php

namespace App\Services\Crm;

use App\Models\Application;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
            'birthday' => $this->formatBirthday($application->birth_date),
            'phone' => $this->formatPhone($application->phone),
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

    private function formatPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $length = strlen($digits);

        if ($length === 11) {
            if (Str::startsWith($digits, '7')) {
                return $digits;
            }

            if (Str::startsWith($digits, '8')) {
                return '7' . substr($digits, 1);
            }

            return '';
        }

        if ($length === 10) {
            return '7' . $digits;
        }

        return '';
    }

    private function formatBirthday(mixed $birthDate): string
    {
        if ($birthDate instanceof \DateTimeInterface) {
            return $birthDate->format('d.m.Y');
        }

        $birthDate = (string) ($birthDate ?? '');

        if ($birthDate === '') {
            return '';
        }

        try {
            return Carbon::parse($birthDate)->format('d.m.Y');
        } catch (\Throwable $exception) {
            return '';
        }
    }
}
