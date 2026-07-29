<?php

namespace Tests\Unit;

use App\Services\BookingMonitoring\BitrixMonitoringClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BitrixMonitoringClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking-monitoring.bitrix.webhook_url' => 'https://clinic.bitrix24.ru/rest/7/secret/',
            'booking-monitoring.bitrix.dialog_id' => 'chat42',
        ]);
    }

    public function test_it_sends_a_message_to_the_configured_group_chat(): void
    {
        Http::fake([
            '*' => Http::response(['result' => 123], 200),
        ]);

        app(BitrixMonitoringClient::class)->send('Проверка завершена');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://clinic.bitrix24.ru/rest/7/secret/im.message.add.json'
                && $request->method() === 'POST'
                && $request['DIALOG_ID'] === 'chat42'
                && $request['MESSAGE'] === 'Проверка завершена';
        });
    }

    public function test_it_throws_when_bitrix_returns_an_api_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'WRONG_REQUEST',
                'error_description' => 'Dialog not found',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dialog not found');

        app(BitrixMonitoringClient::class)->send('Сообщение');
    }
}
