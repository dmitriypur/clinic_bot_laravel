<?php

namespace App\Services\BookingMonitoring;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

final class BitrixMonitoringClient
{
    public function __construct(private readonly HttpFactory $http) {}

    public function send(string $message): void
    {
        $webhookUrl = trim((string) config('booking-monitoring.bitrix.webhook_url'));
        $dialogId = trim((string) config('booking-monitoring.bitrix.dialog_id'));

        if ($webhookUrl === '' || $dialogId === '') {
            throw new RuntimeException('Не настроены webhook или идентификатор чата Bitrix24.');
        }

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(10)
            ->asForm()
            ->post(rtrim($webhookUrl, '/').'/im.message.add.json', [
                'DIALOG_ID' => $dialogId,
                'MESSAGE' => $message,
            ]);

        $response->throw();

        $payload = $response->json();

        if (is_array($payload) && isset($payload['error'])) {
            throw new RuntimeException((string) ($payload['error_description'] ?? $payload['error']));
        }
    }
}
