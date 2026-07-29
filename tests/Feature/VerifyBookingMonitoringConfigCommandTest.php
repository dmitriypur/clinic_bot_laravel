<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VerifyBookingMonitoringConfigCommandTest extends TestCase
{
    public function test_it_accepts_complete_production_configuration_without_exposing_secrets(): void
    {
        config([
            'booking-monitoring.enabled' => true,
            'booking-monitoring.url' => 'http://85.93.54.201:5490/unf_telephony',
            'booking-monitoring.run_times' => ['07:00', '12:00', '17:00'],
            'booking-monitoring.bitrix.webhook_url' => 'https://clinic.bitrix24.ru/rest/7/top-secret/',
            'booking-monitoring.bitrix.dialog_id' => 'chat42',
            'queue.default' => 'database',
        ]);

        $exitCode = Artisan::call('booking:verify-monitoring-config');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Настройки мониторинга корректны', $output);
        $this->assertStringNotContainsString('top-secret', $output);
    }

    public function test_it_allows_deployment_when_monitoring_is_intentionally_disabled(): void
    {
        config([
            'booking-monitoring.enabled' => false,
            'booking-monitoring.bitrix.webhook_url' => null,
            'booking-monitoring.bitrix.dialog_id' => null,
            'queue.default' => 'sync',
        ]);

        $exitCode = Artisan::call('booking:verify-monitoring-config');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Мониторинг отключён', $output);
        $this->assertStringNotContainsString('Webhook Bitrix24', $output);
    }

    public function test_it_rejects_a_bitrix_method_url_instead_of_the_webhook_base_url(): void
    {
        config([
            'booking-monitoring.enabled' => true,
            'booking-monitoring.url' => 'http://85.93.54.201:5490/unf_telephony',
            'booking-monitoring.run_times' => ['07:00', '12:00', '17:00'],
            'booking-monitoring.bitrix.webhook_url' => 'https://clinic.bitrix24.ru/rest/7/secret/im.message.add.json',
            'booking-monitoring.bitrix.dialog_id' => 'chat42',
            'queue.default' => 'database',
        ]);

        $exitCode = Artisan::call('booking:verify-monitoring-config');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('базовым адресом', Artisan::output());
    }
}
