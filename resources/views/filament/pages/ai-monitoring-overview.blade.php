<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Kill Switch Safety Banner --}}
        <div class="rounded-xl border p-5 {{ $isAiEnabled ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/30' }}">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-3 w-3 rounded-full {{ $isAiEnabled ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500' }}"></span>
                        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                            {{ $isAiEnabled ? 'AI сервис активен и готов к работе' : 'AI сервис АВАРИЙНО ОТКЛЮЧЕН (Kill-Switch)' }}
                        </h3>
                    </div>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        {{ $isAiEnabled ? 'Все запросы обрабатываются в соответствии с установленными лимитами и политиками безопасности.' : 'Все внешние вызовы LLM и запуск AI-пайплайнов заблокированы для всей организации.' }}
                    </p>
                </div>
                <div>
                    <button
                        wire:click="toggleKillSwitch"
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium transition-colors shadow-sm {{ $isAiEnabled ? 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-2 focus:ring-rose-500' : 'bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-2 focus:ring-emerald-500' }}"
                    >
                        {{ $isAiEnabled ? 'Аварийно отключить (Kill-Switch)' : 'Включить AI сервис' }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Top KPI Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Дневной расход</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                        ${{ number_format($spentTodayMinor / 100, 2) }}
                    </span>
                    <span class="text-xs text-slate-500">из ${{ number_format($maxDailySpendMinor / 100, 2) }}</span>
                </div>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    @php
                        $percent = $maxDailySpendMinor > 0 ? min(100, round((($spentTodayMinor + $reservedTodayMinor) / $maxDailySpendMinor) * 100)) : 0;
                    @endphp
                    <div class="h-full bg-slate-900 dark:bg-slate-100 transition-all duration-300" style="width: {{ $percent }}%"></div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Зарезервировано</p>
                <div class="mt-2">
                    <span class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                        ${{ number_format($reservedTodayMinor / 100, 2) }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Активные и выполняемые попытки</p>
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

        {{-- Provider Status Table --}}
        <div class="rounded-xl border border-slate-200 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900 overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Подключенные AI-провайдеры</h4>
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
                                {{ $provider->is_enabled ? 'Включен' : 'Отключен' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-slate-500">
                        Нет настроенных провайдеров. Перейдите в раздел «Провайдеры и модели» для настройки ключей API.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
