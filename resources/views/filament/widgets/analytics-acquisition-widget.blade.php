<x-filament-widgets::widget>
    @php($data = $this->getData())

    <x-filament::section heading="Привлечение" description="Новые клиенты и первый зафиксированный источник">
        <div class="grid gap-4 lg:grid-cols-[minmax(12rem,0.7fr)_minmax(0,1.3fr)]">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Новые клиенты</p>
                <p class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $data?->newClients ?? 0 }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Созданы за выбранный период</p>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Источники новых клиентов</h3>

                @if ($data === null || $data->sources === [])
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Нет данных за выбранный период.</p>
                @else
                    <div class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($data->sources as $source)
                            <div class="flex items-center justify-between gap-4 py-2 text-sm">
                                <span class="min-w-0 truncate text-gray-600 dark:text-gray-300">{{ $source->label }}</span>
                                <span class="shrink-0 font-medium text-gray-950 dark:text-white">{{ $source->count }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
