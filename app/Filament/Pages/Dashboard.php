<?php

namespace App\Filament\Pages;

use App\Services\BookingMonitoring\BookingAvailabilityChecker;
use App\Services\BookingMonitoring\BookingMonitoringNotifier;
use App\Support\CalendarSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static string $view = 'filament.pages.dashboard';

    protected static ?string $title = 'Дашборд';

    protected static ?string $navigationLabel = 'Дашборд';

    protected static ?int $navigationSort = 1;

    /**
     * Флаг включения календаря заявок
     */
    public bool $isCalendarEnabled = true;

    /**
     * Активный таб по умолчанию
     */
    public ?string $activeTab = 'appointments';

    /**
     * Инициализация активного таба
     */
    public function mount(): void
    {
        $this->isCalendarEnabled = CalendarSettings::isEnabledForUser(Auth::user());

        if (! $this->isCalendarEnabled) {
            $this->activeTab = 'schedule';
        } elseif (! $this->activeTab) {
            $this->activeTab = 'appointments';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkBookingAvailability')
                ->label('Проверить доступность 1С')
                ->icon('heroicon-o-signal')
                ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                ->action(function (
                    BookingAvailabilityChecker $checker,
                    BookingMonitoringNotifier $notifier,
                ): void {
                    $this->checkBookingAvailability($checker, $notifier);
                }),
        ];
    }

    public function checkBookingAvailability(
        BookingAvailabilityChecker $checker,
        BookingMonitoringNotifier $notifier,
    ): void {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        if (! config('booking-monitoring.enabled')) {
            Notification::make()
                ->title('Мониторинг отключён')
                ->warning()
                ->send();

            return;
        }

        $lock = Cache::lock(
            'booking-monitoring:availability-check',
            (int) config('booking-monitoring.lock_seconds', 60),
        );

        if (! $lock->get()) {
            Notification::make()
                ->title('Проверка уже выполняется')
                ->warning()
                ->send();

            return;
        }

        try {
            $result = $checker->check();
            $notifier->manualCheck($result);

            $notification = Notification::make()
                ->title($result->available ? 'Запись в 1С доступна' : 'Запись в 1С недоступна')
                ->body($result->available
                    ? "HTTP {$result->statusCode}, время ответа: {$result->durationMs} мс."
                    : ($result->error ?? 'Сервер 1С не ответил.'));

            if ($result->available) {
                $notification->success();
            } else {
                $notification->danger();
            }

            $notification->send();
        } finally {
            $lock->release();
        }
    }

    public function updatedIsCalendarEnabled($value): void
    {
        $user = Auth::user();

        if ($user && $user->isDoctor()) {
            $this->isCalendarEnabled = true;
            $this->activeTab = $this->activeTab ?? 'appointments';

            return;
        }

        if ($user && $user->isPartner()) {
            $this->isCalendarEnabled = CalendarSettings::isEnabledForUser($user);
            $this->activeTab = $this->isCalendarEnabled ? ($this->activeTab ?? 'appointments') : 'schedule';

            return;
        }

        if (! $user || (! $user->isSuperAdmin() && ! $user->hasRole('admin'))) {
            $this->isCalendarEnabled = CalendarSettings::isEnabledForUser($user);
            $this->activeTab = $this->isCalendarEnabled ? ($this->activeTab ?? 'appointments') : 'schedule';

            return;
        }

        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = $enabled ?? false;

        CalendarSettings::setEnabledForUser($user, $enabled);

        if (! $enabled) {
            $this->activeTab = 'schedule';
        } elseif ($this->activeTab !== 'appointments') {
            $this->activeTab = 'appointments';
        }

        $this->isCalendarEnabled = $enabled;
    }
}
