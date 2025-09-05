<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Табы -->
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-1">
            <div class="flex space-x-1" role="tablist">
                <button 
                    wire:click="$set('activeTab', 'calendar')"
                    role="tab"
                    class="flex-1 inline-flex items-center justify-center px-4 py-3 text-sm font-medium rounded-md transition-all duration-200 {{ $activeTab === 'calendar' ? 'bg-primary-500 text-white shadow-lg transform scale-105' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                >
                    <x-heroicon-o-calendar-days class="w-5 h-5 mr-2" />
                    Календарь заявок
                </button>
                
                <button 
                    wire:click="$set('activeTab', 'list')"
                    role="tab"
                    class="flex-1 inline-flex items-center justify-center px-4 py-3 text-sm font-medium rounded-md transition-all duration-200 {{ $activeTab === 'list' ? 'bg-primary-500 text-white shadow-lg transform scale-105' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                >
                    <x-heroicon-o-rectangle-stack class="w-5 h-5 mr-2" />
                    Список заявок
                </button>
            </div>
        </div>

        <!-- Контент табов -->
        <div class="tab-content">
            @if($activeTab === 'calendar')
                <!-- Календарь заявок -->
                <div class="calendar-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Resources\ApplicationResource\Widgets\AppointmentCalendarWidget::class)
                    </div>
                </div>
            @else
                <!-- Список заявок -->
                <div class="list-tab">
                    {{ $this->table }}
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>