<x-filament-widgets::widget>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Фильтры календаря</h3>
            <div class="flex items-center space-x-2">
                <button
                    wire:click="toggleFilters"
                    class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                    </svg>
                    {{ $showFilters ? 'Скрыть' : 'Показать' }} фильтры
                </button>
                
                <button
                    wire:click="clearFilters"
                    class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Очистить
                </button>
            </div>
        </div>

        @if($showFilters)
            <div class="border-t border-gray-200 pt-4">
                <form wire:submit.prevent="updateFilters">
                    {{ $this->form }}
                    
                    <div class="mt-4 flex justify-end">
                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            Применить фильтры
                        </button>
                    </div>
                </form>
            </div>
        @endif

        @if(!empty($filters['clinic_ids']) || !empty($filters['branch_ids']) || !empty($filters['doctor_ids']) || !empty($filters['date_from']) || !empty($filters['date_to']))
            <div class="mt-4 pt-4 border-t border-gray-200">
                <h4 class="text-sm font-medium text-gray-700 mb-2">Активные фильтры:</h4>
                <div class="flex flex-wrap gap-2">
                    @if(!empty($filters['date_from']))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            С: {{ \Carbon\Carbon::parse($filters['date_from'])->format('d.m.Y') }}
                        </span>
                    @endif
                    
                    @if(!empty($filters['date_to']))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            По: {{ \Carbon\Carbon::parse($filters['date_to'])->format('d.m.Y') }}
                        </span>
                    @endif
                    
                    @if(!empty($filters['clinic_ids']))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Клиники: {{ count($filters['clinic_ids']) }}
                        </span>
                    @endif
                    
                    @if(!empty($filters['branch_ids']))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                            Филиалы: {{ count($filters['branch_ids']) }}
                        </span>
                    @endif
                    
                    @if(!empty($filters['doctor_ids']))
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            Врачи: {{ count($filters['doctor_ids']) }}
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
