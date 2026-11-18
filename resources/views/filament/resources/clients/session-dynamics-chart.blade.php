@php
    $sessions = is_array($chart['sessions'] ?? null) ? $chart['sessions'] : [];
    $metrics = is_array($chart['metrics'] ?? null) ? $chart['metrics'] : [];
    $pains = is_array($chart['pains'] ?? null) ? $chart['pains'] : [];
    $tests = is_array($chart['tests'] ?? null) ? $chart['tests'] : [];
    $defaultTab = $metrics !== [] ? 'metrics' : ($pains !== [] ? 'pains' : 'tests');
    $defaultMetric = $metrics[0]['key'] ?? null;
@endphp

<div
    class="min-w-0 space-y-4 text-sm text-gray-700 dark:text-gray-200"
    data-testid="session-dynamics-chart"
    x-data="{ activeTab: @js($defaultTab), activeMetric: @js($defaultMetric) }"
>
    <div class="min-w-0">
        <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="break-words text-base font-semibold text-gray-950 dark:text-white">{{ __('Динамика медицинских показателей') }}</h3>
                <p class="mt-1 max-w-3xl break-words text-xs leading-5 text-gray-500 dark:text-gray-400">
                    {{ __('Сохранённые значения текущего и до 9 предыдущих сеансов. График не делает медицинских выводов.') }}
                </p>
            </div>
            @if ($sessions !== [])
                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                    {{ __('Сеансов: :count', ['count' => count($sessions)]) }}
                </span>
            @endif
        </div>
    </div>

    @if ($metrics === [] && $pains === [] && $tests === [])
        <p class="rounded-lg border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
            {{ __('Сохранённых сопоставимых значений для графика пока нет.') }}
        </p>
    @else
        <div class="min-w-0 overflow-x-auto pb-1">
            <div class="flex min-w-0 flex-wrap gap-2" role="tablist" aria-label="{{ __('Разделы динамики') }}">
                @if ($metrics !== [])
                    <button
                        type="button"
                        role="tab"
                        aria-controls="session-dynamics-metrics"
                        x-bind:aria-selected="activeTab === 'metrics'"
                        x-bind:class="activeTab === 'metrics' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                        class="rounded-lg px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-primary-500"
                        @click="activeTab = 'metrics'"
                    >{{ __('Показатели') }}</button>
                @endif
                @if ($pains !== [])
                    <button
                        type="button"
                        role="tab"
                        aria-controls="session-dynamics-pains"
                        x-bind:aria-selected="activeTab === 'pains'"
                        x-bind:class="activeTab === 'pains' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                        class="rounded-lg px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-primary-500"
                        @click="activeTab = 'pains'"
                    >{{ __('Боль') }}</button>
                @endif
                @if ($tests !== [])
                    <button
                        type="button"
                        role="tab"
                        aria-controls="session-dynamics-tests"
                        x-bind:aria-selected="activeTab === 'tests'"
                        x-bind:class="activeTab === 'tests' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                        class="rounded-lg px-3 py-2 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-primary-500"
                        @click="activeTab = 'tests'"
                    >{{ __('Тесты') }}</button>
                @endif
            </div>
        </div>

        @if ($metrics !== [])
            <section id="session-dynamics-metrics" x-show="activeTab === 'metrics'" x-cloak role="tabpanel" aria-label="{{ __('Показатели') }}" class="min-w-0 space-y-3">
                <div class="flex min-w-0 flex-wrap gap-2" aria-label="{{ __('Выбор показателя') }}">
                    @foreach ($metrics as $metric)
                        <button
                            type="button"
                            x-bind:class="activeMetric === @js($metric['key']) ? 'border-primary-600 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950/30 dark:text-primary-200' : 'border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200'"
                            class="min-w-0 max-w-full rounded-lg border px-3 py-2 text-left text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-primary-500"
                            @click="activeMetric = @js($metric['key'])"
                        >
                            <span class="block break-words font-medium">{{ $metric['label'] }}</span>
                            <span class="block break-words text-xs opacity-75">{{ $metric['unit'] }}</span>
                        </button>
                    @endforeach
                </div>

                @foreach ($metrics as $metric)
                    <div x-show="activeMetric === @js($metric['key'])" x-cloak>
                        @include('filament.resources.clients.session-dynamics-chart-series', ['series' => $metric, 'sessions' => $sessions])
                    </div>
                @endforeach
            </section>
        @endif

        @if ($pains !== [])
            <section id="session-dynamics-pains" x-show="activeTab === 'pains'" x-cloak role="tabpanel" aria-label="{{ __('Боль') }}" class="min-w-0 space-y-3">
                @foreach ($pains as $pain)
                    @include('filament.resources.clients.session-dynamics-chart-series', ['series' => $pain, 'sessions' => $sessions])
                @endforeach
            </section>
        @endif

        @if ($tests !== [])
            <section id="session-dynamics-tests" x-show="activeTab === 'tests'" x-cloak role="tabpanel" aria-label="{{ __('Тесты') }}" class="min-w-0 space-y-3">
                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                    {{ __('Показаны только одинаковые тесты с сохранённой совместимой версией и единицей измерения. Несопоставимые результаты разделены.') }}
                </p>
                @foreach ($tests as $test)
                    <article class="min-w-0 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-white/5 sm:p-4">
                        <h4 class="break-words text-sm font-semibold text-gray-950 dark:text-white">{{ $test['label'] }}</h4>
                        @if ($test['note'])
                            <p class="mt-1 break-words text-xs text-gray-500 dark:text-gray-400">{{ $test['note'] }}</p>
                        @endif
                        <div class="mt-3 grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($test['sessions'] as $testSession)
                                <div class="min-w-0 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                    <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-200">{{ $testSession['date'] }}</span>
                                        @if ($testSession['is_current'])
                                            <span class="text-xs text-primary-600 dark:text-primary-300">{{ __('Текущий') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 grid min-w-0 grid-cols-2 gap-2">
                                        <div class="min-w-0 rounded-md bg-primary-50 p-2 dark:bg-primary-950/30">
                                            <div class="text-xs text-primary-700 dark:text-primary-200">{{ __('До') }}</div>
                                            <div class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">{{ $testSession['before'] ?? __('Пропуск') }}</div>
                                        </div>
                                        <div class="min-w-0 rounded-md bg-warning-50 p-2 dark:bg-warning-950/30">
                                            <div class="text-xs text-warning-700 dark:text-warning-200">{{ __('После') }}</div>
                                            <div class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">{{ $testSession['after'] ?? __('Пропуск') }}</div>
                                        </div>
                                    </div>
                                    <p class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400" title="{{ $testSession['full_date'] }}">{{ $testSession['period'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    @endif
</div>
