<?php

namespace Tests\Feature;

use App\Jobs\SendBookingMonitoringMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingAvailabilityCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        config([
            'booking-monitoring.enabled' => true,
            'booking-monitoring.url' => 'http://85.93.54.201:5490/unf_telephony',
            'booking-monitoring.lock_seconds' => 60,
        ]);
    }

    public function test_the_command_checks_availability_and_queues_every_result(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('booking:check-availability');

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
        Queue::assertPushed(SendBookingMonitoringMessage::class);
    }

    public function test_the_command_does_nothing_when_monitoring_is_disabled(): void
    {
        config(['booking-monitoring.enabled' => false]);
        Http::fake();

        $exitCode = Artisan::call('booking:check-availability');

        $this->assertSame(0, $exitCode);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_scheduler_runs_the_check_three_times_in_moscow_timezone(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'booking:check-availability'));

        $this->assertCount(3, $events);
        $this->assertSame(
            ['0 7 * * *', '0 12 * * *', '0 17 * * *'],
            $events->pluck('expression')->values()->all()
        );
        $this->assertSame(
            ['Europe/Moscow'],
            $events->pluck('timezone')->unique()->values()->all()
        );
    }
}
