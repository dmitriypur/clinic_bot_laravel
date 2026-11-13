<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_automatic_deploy_validates_monitoring_without_overwriting_server_environment(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy.yml');
        $deploy = file_get_contents(dirname(__DIR__, 2).'/scripts/deploy-release.sh');

        $this->assertStringContainsString('booking:verify-monitoring-config', $deploy);
        $this->assertStringContainsString('queue:restart', $deploy);
        $this->assertStringContainsString('systemctl is-active --quiet cron', $deploy);
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

    public function test_process_setup_waits_for_workers_to_finish_graceful_restart(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/setup-production-processes.sh');

        $this->assertStringContainsString('PROCESSES_WORKER_WAIT_SECONDS=30', $script);
        $this->assertStringContainsString('sleep 1', $script);
        $this->assertStringContainsString('Laravel queue worker не перешли в состояние RUNNING за', $script);
    }
}
