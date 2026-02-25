<?php

namespace Tests\Feature;

use App\Filament\Widgets\AppointmentCalendarWidget;
use App\Models\Application;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class AppointmentCalendarWidgetDeleteTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_custom_delete_action_clears_record_state_after_deletion(): void
    {
        session()->start();

        $application = Mockery::mock(Application::class)->makePartial();
        $application->integration_type = Application::INTEGRATION_TYPE_LOCAL;
        $application->external_appointment_id = null;
        $application->shouldReceive('delete')->once();

        $widget = new AppointmentCalendarWidgetDeleteHarness();
        $widget->setRecordForTest($application);
        $widget->triggerDeleteForTest(true);

        $this->assertNull($widget->record);
        $this->assertTrue($widget->refreshTriggered);
    }
}

class AppointmentCalendarWidgetDeleteHarness extends AppointmentCalendarWidget
{
    public bool $refreshTriggered = false;

    public function setRecordForTest(Application $application): void
    {
        $this->record = $application;
    }

    public function triggerDeleteForTest(bool $closeModal = false): void
    {
        $this->deleteCurrentRecordWithOneCHandling($closeModal);
    }

    public function refreshRecords(): void
    {
        $this->refreshTriggered = true;
    }
}
