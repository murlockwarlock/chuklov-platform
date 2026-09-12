<x-filament-widgets::widget>
    @php($data = $this->getData())
    @php($total = $data?->newClients ?? 0)
    @php($sourceTotal = collect($data?->sources ?? [])->sum('count'))

    <x-filament::section heading="Привлечение" description="Новые клиенты и первый зафиксированный источник">
        <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(12rem,0.55fr)_minmax(0,1.45fr)]">
            <div class="rounded-xl bg-gray-50 p-5 dark:bg-white/5">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Новые клиенты</p>
                <p class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $total }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Созданы за выбранный период</p>
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-medium text-gray-950 dark:text-white">Источники новых клиентов</h3>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Итого: {{ $sourceTotal }} из {{ $total }}</span>
                </div>
                @if ($data === null || $data->sources === [])
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Нет данных об источниках за выбранный период.</p>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach ($data->sources as $source)
                            @php($percentage = $total > 0 ? round($source->count / $total * 100, 1) : 0)
                            <div class="min-w-0">
                                <div class="flex min-w-0 items-center justify-between gap-3 text-sm">
                                    <span class="min-w-0 break-words text-gray-700 dark:text-gray-200">{{ $source->label }}</span>
                                    <span class="shrink-0 font-semibold text-gray-950 dark:text-white">{{ $source->count }} · {{ number_format($percentage, 1, ',', ' ') }}%</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="{{ $source->label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}">
                                    <div class="h-full rounded-full bg-primary-500" style="width: {{ min(100, max(0, $percentage)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
