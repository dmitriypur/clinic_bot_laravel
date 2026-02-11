<x-filament-widgets::widget>
    <x-filament::section heading="1С интеграция">
        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Всего эндпоинтов</div>
                <div class="mt-1 text-2xl font-semibold">{{ $total }}</div>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 shadow-sm">
                <div class="text-xs uppercase text-amber-600">Давно не синхронизировались (&gt;2ч)</div>
                <div class="mt-1 text-2xl font-semibold text-amber-700">{{ $stale->count() }}</div>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 shadow-sm">
                <div class="text-xs uppercase text-rose-600">С ошибками</div>
                <div class="mt-1 text-2xl font-semibold text-rose-700">{{ $failed->count() }}</div>
            </div>
        </div>

        <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium text-gray-500">Клиника</th>
                        <th class="px-4 py-2 font-medium text-gray-500">Филиал</th>
                        <th class="px-4 py-2 font-medium text-gray-500">Последний успех</th>
                        <th class="px-4 py-2 font-medium text-gray-500">Ошибка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($endpoints as $endpoint)
                        <tr>
                            <td class="px-4 py-2">{{ $endpoint->clinic?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $endpoint->branch?->name ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if($endpoint->last_success_at)
                                    {{ $endpoint->last_success_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                                @else
                                    <span class="text-gray-400">нет данных</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if($endpoint->last_error_message)
                                    <span class="text-rose-600">{{ \Illuminate\Support\Str::limit($endpoint->last_error_message, 80) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-4 text-center text-gray-500">Активных эндпоинтов не найдено</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
