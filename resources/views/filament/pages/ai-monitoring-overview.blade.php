<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border p-5 {{ $clientCompanion['ready'] ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20' : ($clientCompanion['status'] === 'disabled' ? 'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/30' : 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/20') }}">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-3 w-3 rounded-full {{ $clientCompanion['ready'] ? 'bg-emerald-500 animate-pulse' : ($clientCompanion['status'] === 'disabled' ? 'bg-rose-500' : 'bg-amber-500') }}"></span>
                        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                            @if($clientCompanion['status'] === 'disabled')
                                AI временно отключён
                            @elseif($clientCompanion['ready'])
                                AI-компаньон готов к работе
                            @elseif($clientCompanion['status'] === 'provider_unavailable')
                                AI-компаньон временно недоступен
                            @else
                                AI-компаньон требует настройки
                            @endif
                        </h3>
                    </div>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        @if(!$isAiEnabled)
                            Новые платные AI-запросы временно остановлены для всей организации.
                        @elseif($clientCompanion['status'] === 'disabled')
                            Сценарий клиентского компаньона отключён в ограничениях AI.
                        @elseif($clientCompanion['ready'])
                            Запросы обрабатываются в соответствии с установленным дневным бюджетом.
                        @elseif($clientCompanion['status'] === 'provider_unavailable')
                            Настройка сохранена, но AI сейчас недоступен. Проверьте подключение и повторите попытку.
                        @else
                            Завершите настройку AI-компаньона.
                        @endif
                    </p>
                    @if(!$clientCompanion['ready'])
                        <ul class="mt-3 space-y-1 text-sm text-slate-700 dark:text-slate-300">
                            @foreach($clientCompanion['issues'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($canManageAi && in_array($clientCompanion['status'], ['needs_setup', 'provider_unavailable'], true))
                        <a class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" href="{{ $clientCompanion['promptUrl'] }}">Настроить промпт</a>
                        <a class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" href="{{ $clientCompanion['providerUrl'] }}">Настроить модель</a>
                    @endif
                    <button
                        wire:click="toggleKillSwitch"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium transition-colors shadow-sm {{ $isAiEnabled ? 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-2 focus:ring-rose-500' : 'bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500' }}"
                    >
                        {{ $isAiEnabled ? 'Отключить AI' : 'Включить AI' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Дневной расход</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                        ${{ $spentToday }}
                    </span>
                    <span class="text-xs text-slate-500">из ${{ $maxDailySpend }}</span>
                </div>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full bg-slate-900 transition-all duration-300 dark:bg-slate-100" style="width: {{ $spendPercent }}%"></div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Зарезервировано</p>
                <div class="mt-2">
                    <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                        ${{ $reservedToday }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Ожидаемые расходы текущих запросов</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Запусков сегодня</p>
                <div class="mt-2">
                    <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                        {{ $runsCountToday }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Обработано сценариев и запросов</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Ошибки / Сбои</p>
                <div class="mt-2">
                    <span class="text-2xl font-bold tracking-tight {{ $failedRunsCountToday > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}">
                        {{ $failedRunsCountToday }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    {{ $runsCountToday > 0 ? round(($failedRunsCountToday / $runsCountToday) * 100, 1) : 0 }}% отказов сегодня
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900 overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Подключённые сервисы AI</h4>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse($providers as $provider)
                    <div class="flex items-center justify-between px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-2.5 w-2.5 rounded-full {{ $provider->is_enabled ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                            <div>
                                <h5 class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $provider->display_name ?: $provider->provider_name }}</h5>
                                <p class="text-xs text-slate-500">Моделей: {{ $provider->models_count }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium {{ $provider->is_enabled ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                                {{ $provider->is_enabled ? 'Включён' : 'Отключён' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-slate-500">
                        Добавьте подключение AI в разделе «Провайдеры и модели».
                    </div>
                @endforelse
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
