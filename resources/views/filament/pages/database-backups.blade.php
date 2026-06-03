<x-filament-panels::page>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="px-3 py-2 font-medium">Файл</th>
                        <th class="px-3 py-2 font-medium">Размер</th>
                        <th class="px-3 py-2 font-medium">Создан</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($backups as $backup)
                        <tr class="border-b">
                            <td class="px-3 py-2">{{ $backup['file_name'] }}</td>
                            <td class="px-3 py-2">{{ $this->humanSize((int) $backup['size']) }}</td>
                            <td class="px-3 py-2">{{ \Carbon\Carbon::createFromTimestamp($backup['last_modified'])->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-filament::button
                                    tag="a"
                                    :href="$this->downloadUrl($backup['path'])"
                                    icon="heroicon-o-arrow-down-tray"
                                >
                                    Скачать
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-6 text-center text-gray-500" colspan="4">
                                Дампов пока нет.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
