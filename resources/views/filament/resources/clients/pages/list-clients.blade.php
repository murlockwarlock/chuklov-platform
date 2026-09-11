<x-filament-panels::page>
    @php($counts = $this->segmentCounts)
    @php($labels = $this->segmentLabels())

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="min-w-0">
            {{ $this->table }}
        </div>

        <aside class="min-w-0">
            <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 xl:hidden">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">
                    <span class="flex min-w-0 items-center gap-2">
                        <x-filament::icon icon="heroicon-o-tag" class="size-5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                        <span>Категории</span>
                    </span>
                </summary>
                <div class="border-t border-gray-200 p-3 dark:border-gray-800">
                    <div class="grid gap-1">
                        @foreach ($labels as $key => $label)
                            <button type="button" wire:click="selectSegment('{{ $key }}')" class="flex min-w-0 items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm transition {{ $this->selectedSegment() === $key ? 'bg-primary-50 text-primary-800 dark:bg-primary-950/40 dark:text-primary-200' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800' }}">
                                <span class="min-w-0 truncate">{{ $label }}</span>
                                <span class="shrink-0 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $counts[$key] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </details>

            <div class="hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 xl:block">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-tag" class="size-5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Категории</h2>
                </div>
                <div class="mt-3 grid gap-1">
                    @foreach ($labels as $key => $label)
                        <button type="button" wire:click="selectSegment('{{ $key }}')" class="flex min-w-0 items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm transition {{ $this->selectedSegment() === $key ? 'bg-primary-50 text-primary-800 dark:bg-primary-950/40 dark:text-primary-200' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800' }}">
                            <span class="min-w-0 truncate">{{ $label }}</span>
                            <span class="shrink-0 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $counts[$key] ?? 0 }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="mt-4 text-xs leading-5 text-gray-500 dark:text-gray-400">Сегменты пересекаются: один клиент может одновременно быть новым и не иметь завершённых визитов.</p>
            </div>
        </aside>
    </div>
</x-filament-panels::page>
