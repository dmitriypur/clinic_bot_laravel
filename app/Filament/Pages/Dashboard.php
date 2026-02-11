<?php

namespace App\Filament\Pages;

use App\Support\CalendarSettings;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

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
