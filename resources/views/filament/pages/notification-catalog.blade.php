<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Здесь видны действующие сценарии, получатели, каналы, версия сообщения и фактическое состояние доставки. Настройка ведётся через существующие авто-сообщения и шаблоны.
        </p>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($this->rows() as $row)
                <article class="min-w-0 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="min-w-0 text-base font-semibold text-gray-950 dark:text-white">{{ $row['label'] }}</h2>
                        <span class="shrink-0 rounded-full px-2 py-1 text-xs font-medium {{ $row['enabled'] ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' }}">
                            {{ $row['enabled'] ? 'Включено' : 'Выключено' }}
                        </span>
                    </div>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Получатели</dt>
                            <dd class="mt-1 break-words text-gray-900 dark:text-gray-100">{{ $row['recipients'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Каналы</dt>
                            <dd class="mt-1 break-words text-gray-900 dark:text-gray-100">{{ $row['channels'] === [] ? 'Нет' : implode(', ', $row['channels']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Шаблон</dt>
                            <dd class="mt-1 break-words text-gray-900 dark:text-gray-100">{{ $row['template'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Доставка</dt>
                            <dd class="mt-1 break-words text-gray-900 dark:text-gray-100">{{ $row['delivery'] }}</dd>
                        </div>
                    </dl>
                    <div class="mt-5 flex flex-wrap gap-3 text-sm">
                        <a class="font-medium text-primary-600 hover:text-primary-500" href="{{ $row['rulesUrl'] }}">Авто-сообщения</a>
                        <a class="font-medium text-primary-600 hover:text-primary-500" href="{{ $row['templatesUrl'] }}">Шаблоны</a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
