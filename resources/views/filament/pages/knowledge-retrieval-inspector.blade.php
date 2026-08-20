<x-filament-panels::page>
    {{ $this->content }}

    @if ($results !== [])
        <div class="space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Количество фрагментов: {{ count($results) }}</p>
            @foreach ($results as $result)
                <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-sm text-gray-500">Результат {{ $result['rank'] }} · {{ $result['source_title'] }}</p>
                    <p class="mt-3 whitespace-pre-wrap text-sm text-gray-950 dark:text-white">{{ $result['content'] }}</p>
                </section>
            @endforeach
        </div>
    @elseif ($hasSearched)
        <p class="text-sm text-gray-500 dark:text-gray-400">Подходящих фрагментов не найдено.</p>
    @endif
</x-filament-panels::page>
