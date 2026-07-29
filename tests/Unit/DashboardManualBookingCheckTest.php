<?php

namespace Tests\Unit;

use App\Filament\Pages\Dashboard;
use App\Jobs\SendBookingMonitoringMessage;
use App\Models\User;
use App\Services\BookingMonitoring\BookingAvailabilityChecker;
use App\Services\BookingMonitoring\BookingMonitoringNotifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardManualBookingCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking-monitoring.enabled' => true,
            'booking-monitoring.url' => 'http://85.93.54.201:5490/unf_telephony',
            'booking-monitoring.lock_seconds' => 60,
        ]);
        Cache::flush();
    }

    public function test_manual_action_is_visible_only_to_super_admin(): void
    {
        $this->authenticateUser(isSuperAdmin: true);
        $this->assertTrue($this->dashboardAction()->isVisible());

        $this->authenticateUser(isSuperAdmin: false);
        $this->assertFalse($this->dashboardAction()->isVisible());
    }

    public function test_disabled_monitoring_does_not_contact_one_c_or_bitrix(): void
    {
        $this->authenticateUser(isSuperAdmin: true);
        config(['booking-monitoring.enabled' => false]);
        Http::fake();
        Queue::fake();

        $this->dashboard()->checkBookingAvailability(
            app(BookingAvailabilityChecker::class),
            app(BookingMonitoringNotifier::class),
        );

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Notification::assertNotified('Мониторинг отключён');
    }

    public function test_non_super_admin_cannot_invoke_manual_check_directly(): void
    {
        $this->authenticateUser(isSuperAdmin: false);
        Http::fake();

        try {
            $this->dashboard()->checkBookingAvailability(
                app(BookingAvailabilityChecker::class),
                app(BookingMonitoringNotifier::class),
            );

            $this->fail('Ожидался запрет ручной проверки для пользователя без роли super_admin.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Http::assertNothingSent();
    }

    public function test_successful_manual_check_notifies_dashboard_and_bitrix(): void
    {
        $this->authenticateUser(isSuperAdmin: true);
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $this->dashboard()->checkBookingAvailability(
            app(BookingAvailabilityChecker::class),
            app(BookingMonitoringNotifier::class),
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
        Queue::assertPushed(SendBookingMonitoringMessage::class, fn ($job): bool => str_contains(
            $job->message(),
            'Ручная проверка 1С',
        ));
        Notification::assertNotified('Запись в 1С доступна');
    }

    public function test_failed_manual_check_notifies_dashboard_and_bitrix(): void
    {
        $this->authenticateUser(isSuperAdmin: true);
        Http::fake(['*' => Http::response('', 503)]);
        Queue::fake();

        $this->dashboard()->checkBookingAvailability(
            app(BookingAvailabilityChecker::class),
            app(BookingMonitoringNotifier::class),
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
        Queue::assertPushed(SendBookingMonitoringMessage::class, fn ($job): bool => str_contains(
            $job->message(),
            'ЗАПИСЬ НЕДОСТУПНА',
        ));
        Notification::assertNotified('Запись в 1С недоступна');
    }

    public function test_busy_lock_prevents_a_duplicate_manual_check(): void
    {
        $this->authenticateUser(isSuperAdmin: true);
        Http::fake();
        Queue::fake();
        $lock = Cache::lock('booking-monitoring:availability-check', 60);
        $this->assertTrue($lock->get());

        try {
            $this->dashboard()->checkBookingAvailability(
                app(BookingAvailabilityChecker::class),
                app(BookingMonitoringNotifier::class),
            );
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Notification::assertNotified('Проверка уже выполняется');
    }

    private function authenticateUser(bool $isSuperAdmin): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isSuperAdmin')->andReturn($isSuperAdmin);

        Auth::setUser($user);
    }

    private function dashboard(): TestableDashboard
    {
        return app(TestableDashboard::class);
    }

    private function dashboardAction(): Action
    {
        return $this->dashboard()->headerActionsForTest()[0];
    }
}

final class TestableDashboard extends Dashboard
{
    /** @return array<Action> */
    public function headerActionsForTest(): array
    {
        return $this->getHeaderActions();
    }
}
