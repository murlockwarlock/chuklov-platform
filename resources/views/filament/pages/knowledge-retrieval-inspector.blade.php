<x-filament-panels::page>
    {{ $this->content }}

    @if ($results !== [])
        <div class="space-y-4">
            @foreach ($results as $result)
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
                        <span>{{ $result['source_title'] }} · версия {{ $result['revision_version'] }}</span>
                        <span>Совпадение {{ number_format($result['similarity'] * 100, 1) }}%</span>
                    </div>
                    <p class="mt-3 whitespace-pre-wrap text-sm text-gray-950 dark:text-white">{{ $result['content'] }}</p>
                    @if ($result['source_reference'])
                        <p class="mt-3 text-xs text-gray-500">{{ $result['source_reference'] }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    @elseif ($hasSearched)
        <p class="text-sm text-gray-500 dark:text-gray-400">Подходящих фрагментов не найдено.</p>
    @endif
</x-filament-panels::page>
