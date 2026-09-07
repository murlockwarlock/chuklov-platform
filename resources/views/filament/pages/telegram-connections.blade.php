<x-filament-panels::page>
    {{ $this->content }}

    @if($telegramLink)
        <div class="rounded-xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-900 dark:bg-primary-950/30">
            <p class="text-sm font-medium text-gray-950 dark:text-white">Ссылка для подключения</p>
            <p class="mt-2 break-all text-sm text-gray-700 dark:text-gray-300">{{ $telegramLink }}</p>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">Ссылка одноразовая и скоро перестанет действовать.</p>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Статус подключений</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($this->connections() as $connection)
                <div class="min-w-0 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-200">{{ $connection }}</div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
