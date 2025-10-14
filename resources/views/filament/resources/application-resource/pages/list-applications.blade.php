<x-filament-panels::page>
    <div class="space-y-6">
        @unless($isCalendarEnabled)
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-600/40 dark:bg-yellow-900/30 dark:text-yellow-100">
                Календарь заявок отключен на дашборде и не загружается в разделе «Журнал приемов». Включить его можно на странице «Дашборд».
            </div>
        @endunless

        @if($isCalendarEnabled)
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
                        Журнал приемов
                    </button>
                </div>
            </div>
        @endif

        <!-- Контент табов -->
        <div class="tab-content">
            @if($isCalendarEnabled && $activeTab === 'calendar')
                <!-- Календарь заявок -->
                <div class="calendar-tab">
                    <div class="fi-wi-widget">
                        @livewire(\App\Filament\Widgets\AppointmentCalendarWidget::class)
                    </div>
                </div>
            @endif

            @if($activeTab === 'list' || ! $isCalendarEnabled)
                <!-- Список заявок -->
                <div class="list-tab">
                    {{ $this->table }}
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
