<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Здесь настраивается, какие операционные события создают уведомления, кому и куда они отправляются.
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
                    <div class="mt-5 space-y-2">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Настройки уведомлений</h3>
                        @forelse ($row['rules'] as $rule)
                            <div class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                                <div class="min-w-0 text-sm">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $rule['channel'] }}</p>
                                    <p class="break-words text-xs text-gray-500 dark:text-gray-400">{{ $rule['recipient'] }} · {{ $rule['template'] }} · Доставка: {{ $rule['delivery'] }}</p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="toggleRule({{ $rule['id'] }})"
                                    wire:loading.attr="disabled"
                                    class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition {{ $rule['enabled'] ? 'bg-success-50 text-success-700 hover:bg-success-100 dark:bg-success-500/10 dark:text-success-400 dark:hover:bg-success-500/20' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/15' }}"
                                >
                                    {{ $rule['enabled'] ? 'Включено' : 'Включить' }}
                                </button>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">Правило не настроено.</p>
                        @endforelse
                    </div>
                    <div class="mt-5 flex flex-wrap gap-3 text-sm">
                        <a class="font-medium text-primary-600 hover:text-primary-500" href="{{ $row['rulesUrl'] }}">Авто-сообщения</a>
                        <a class="font-medium text-primary-600 hover:text-primary-500" href="{{ $row['templatesUrl'] }}">Шаблоны</a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
