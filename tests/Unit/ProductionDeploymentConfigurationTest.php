<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_automatic_deploy_validates_monitoring_without_overwriting_server_environment(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy.yml');

        $this->assertStringContainsString('booking:verify-monitoring-config', $workflow);
        $this->assertStringContainsString('scripts/setup-production-processes.sh', $workflow);
        $this->assertStringNotContainsString('BOOKING_MONITORING_BITRIX_WEBHOOK_URL:', $workflow);
        $this->assertStringNotContainsString('configure-booking-monitoring-env.sh', $workflow);
    }

    public function test_process_setup_adds_scheduler_but_preserves_existing_supervisor_configuration(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/setup-production-processes.sh');

        $this->assertStringContainsString('artisan schedule:run', $script);
        $this->assertStringContainsString('artisan queue:monitor', $script);
        $this->assertStringContainsString('booking:check-availability', $script);
        $this->assertStringContainsString('supervisorctl status', $script);
        $this->assertStringNotContainsString('/etc/supervisor/conf.d/laravel-worker.conf', $script);
        $this->assertStringNotContainsString('supervisorctl restart', $script);
        $this->assertStringNotContainsString('apt-get', $script);
    }
}
