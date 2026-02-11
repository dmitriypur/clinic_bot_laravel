<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Заголовок страницы -->
        <div class="flex items-center justify-between">
            <div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Управление заявками и расписанием врачей
                </p>
            </div>
        </div>

        <!-- Управление календарем заявок -->
        @php
            $user = auth()->user();
            $isDoctor = $user?->isDoctor();
            $isPartner = $user?->isPartner();
            $canToggleCalendar = $user && ($user->isSuperAdmin() || $user->hasRole('admin'));
        @endphp
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Календарь заявок</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if($canToggleCalendar)
                            Включите календарь, если нужно работать с заявками. При отключении календарь не загружается и не расходует ресурсы.
                        @elseif($isDoctor)
                            Календарь доступен для врачей всегда, чтобы вы могли видеть свое расписание и управлять приемами.
                        @else
                            Настройку видимости календаря управляет супер-администратор.
                        @endif
                    </p>
                </div>
                @if($canToggleCalendar)
                    <label class="inline-flex gap-2 items-center cursor-pointer select-none space-x-3 rounded-full focus-within:outline-none focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2">
                        <input 
                            type="checkbox" 
                            wire:model.live="isCalendarEnabled" 
                            class="sr-only focus-visible:outline-none"
                            aria-label="Переключить отображение календаря заявок"
                        >
                        <span class="relative inline-flex h-6 w-11 items-center p-1">
                            <span @class([
                                'absolute inset-0 rounded-full transition-colors duration-200',
                                'bg-primary-500' => $isCalendarEnabled,
                                'bg-gray-200 dark:bg-gray-700' => ! $isCalendarEnabled,
                            ])></span>
                            <span @class([
                                'absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition-transform duration-200 transform',
                                'translate-x-5' => $isCalendarEnabled,
                                'translate-x-0' => ! $isCalendarEnabled,
                            ])></span>
                        </span>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $isCalendarEnabled ? 'Включен' : 'Выключен' }}
                        </span>
                    </label>
                @elseif($isDoctor)
                    <span class="inline-flex items-center rounded-full bg-primary-100 px-3 py-1 text-sm font-medium text-primary-900 dark:bg-primary-500/10 dark:text-primary-200">
                        Всегда включен
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full {{ $isCalendarEnabled ? 'bg-primary-100 text-primary-900 dark:bg-primary-500/10 dark:text-primary-200' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }} px-3 py-1 text-sm font-medium">
                        {{ $isCalendarEnabled ? 'Включен для вашей клиники' : 'Выключен администратором' }}
                    </span>
                @endif
            </div>
        </div>

        <!-- Табы -->
        @if($isCalendarEnabled)
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-1">
                <div class="flex space-x-1" role="tablist">
                    <button 
                        wire:click="$set('activeTab', 'appointments')"
                        role="tab"
                        class="flex-1 inline-flex items-center justify-center px-4 py-3 text-sm font-medium rounded-md transition-all duration-200 {{ $activeTab === 'appointments' ? 'bg-primary-500 text-white shadow-lg transform scale-105' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                    >
                        <x-heroicon-o-calendar-days class="w-5 h-5 mr-2" />
                        Заявки
                    </button>
                    
                    <button 
                        wire:click="$set('activeTab', 'schedule')"
                        role="tab"
                        class="flex-1 inline-flex items-center justify-center px-4 py-3 text-sm font-medium rounded-md transition-all duration-200 {{ $activeTab === 'schedule' ? 'bg-primary-500 text-white shadow-lg transform scale-105' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                    >
                        <x-heroicon-o-clock class="w-5 h-5 mr-2" />
                        Расписание врачей
                    </button>
                </div>
            </div>
        @endif

        <!-- Контент табов -->
        <div class="tab-content">
            @if($isCalendarEnabled && $activeTab === 'appointments')
                <!-- Календарь заявок -->
                <div class="appointments-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Widgets\AppointmentCalendarWidget::class)
                    </div>
                </div>
            @endif

            @if($activeTab === 'schedule' || !$isCalendarEnabled)
                <!-- Расписание врачей -->
                <div class="schedule-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Widgets\AllCabinetsScheduleWidget::class)
                    </div>
                </div>
            @endif
        </div>

        @if($user?->isSuperAdmin())
            <div class="fi-wi-widget">
                @livewire(\App\Filament\Widgets\IntegrationStatusWidget::class)
            </div>
        @endif
    </div>

    <style>
        .tab-content {
            min-height: 600px;
        }
        
        .fi-wi-widget {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        .dark .fi-wi-widget {
            background: #1f2937;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
        }
        
        /* Анимация переключения табов */
        .tab-content > div {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</x-filament-panels::page>
