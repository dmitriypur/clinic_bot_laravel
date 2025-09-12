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

        <!-- Табы -->
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

        <!-- Контент табов -->
        <div class="tab-content">
            @if($activeTab === 'appointments')
                <!-- Календарь заявок -->
                <div class="appointments-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Widgets\AppointmentCalendarWidget::class)
                    </div>
                </div>
            @elseif($activeTab === 'schedule')
                <!-- Расписание врачей -->
                <div class="schedule-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Widgets\AllCabinetsScheduleWidget::class)
                    </div>
                </div>
            @endif
        </div>
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
