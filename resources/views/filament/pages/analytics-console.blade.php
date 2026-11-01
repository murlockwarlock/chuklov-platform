<x-filament-panels::page>
    {{ $this->content }}

    @php
        $unknownAggregateValue = \App\Modules\Analytics\Application\Data\AnalyticsAggregatePredicate::UnknownValue;
    @endphp

    @if ($dimensionErrors !== [])
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100" role="alert">
            <p class="font-medium">{{ __('Некоторые показатели недоступны') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($dimensionErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($cohortPreviewStatus === 'invalid')
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100" role="alert">
            <p class="font-medium">{{ __('Проверьте фильтры') }}</p>
            <p class="mt-1">{{ $cohortPreviewError ?? __('Исправьте значения и повторите действие.') }}</p>
        </div>
    @elseif ($cohortPreviewStatus === 'error')
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-100" role="alert">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-medium">{{ __('Предпросмотр временно недоступен') }}</p>
                    <p class="mt-1">{{ __('Повторите анализ ещё раз.') }}</p>
                </div>
                <button type="button" wire:click="analyze" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-medium text-white hover:bg-red-800">{{ __('Повторить') }}</button>
            </div>
        </div>
    @elseif ($cohortPreviewStatus === 'loading')
        <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200" role="status">
            {{ __('Обновляем предпросмотр…') }}
        </div>
    @endif

    @if ($cohortPreviewStatus === 'success' && $cohortPreviewCount !== null)
        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900" aria-label="{{ __('Клиенты когорты') }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Клиенты когорты') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Найдено клиентов: :count · фильтры применяются одинаково ко всем выбранным показателям.', ['count' => $cohortPreviewCount]) }}</p>
                    @if ($cohortDrilldownReason !== null)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $cohortDrilldownReason }}</p>
                    @endif
                    @if ($cohortClientsTruncated)
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ __('Показаны первые 100 клиентов из найденной выборки.') }}</p>
                    @endif
                </div>
                <button type="button" wire:click="showCohortClients" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500">
                    {{ __('Показать клиентов') }}
                </button>
            </div>
            @if ($cohortClients === [])
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('В этой когорте пока нет клиентов.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">{{ __('Клиент') }}</th>
                            <th class="px-3 py-2">{{ __('Пол') }}</th>
                            <th class="px-3 py-2">{{ __('Дата рождения') }}</th>
                            <th class="px-3 py-2">{{ __('Страна') }}</th>
                            <th class="px-3 py-2">{{ __('Источник') }}</th>
                            <th class="px-3 py-2">{{ __('Почему в когорте') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($cohortClients as $cohortClient)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-3 py-3 font-medium"><a class="text-primary-600 hover:underline" href="{{ $cohortClient['url'] }}">{{ $cohortClient['name'] }}</a></td>
                                <td class="px-3 py-3">{{ $cohortClient['gender'] ?: __('Не указано') }}</td>
                                <td class="px-3 py-3">{{ $cohortClient['birth_date'] ?: __('Не указана') }}</td>
                                <td class="px-3 py-3">{{ $cohortClient['country'] ?: __('Не указана') }}</td>
                                <td class="px-3 py-3">{{ $cohortClient['source'] ?: __('Не указан') }}</td>
                                <td class="px-3 py-3">{{ $cohortClient['reason'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($results === [] && $dimensionErrors === [])
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
            {{ __('Выберите показатели и нажмите «Анализировать».') }}
        </div>
    @elseif ($results === [])
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
            {{ __('Не удалось получить результаты выбранных показателей. Проверьте настройки доступа и повторите анализ.') }}
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            @if (isset($results['custom_metrics']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 xl:col-span-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Вычисляемые метрики') }}</h2>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                            <a class="text-sm text-primary-600 hover:underline" href="{{ \App\Filament\Pages\AnalyticsMetricSettings::getUrl() }}">{{ __('Настроить метрики') }}</a>
                        </div>
                    </div>
                    @if ($results['custom_metrics']['metrics'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Активных метрик пока нет.') }}</p>
                    @else
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[520px] text-left text-sm">
                                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2">{{ __('Показатель') }}</th>
                                    <th class="px-3 py-2">{{ __('Значение') }}</th>
                                    <th class="px-3 py-2">{{ __('Единица') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($results['custom_metrics']['metrics'] as $metric)
                                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                        <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">{{ $metric['name'] }}</td>
                                        <td class="px-3 py-3">{{ $metric['display'] }}</td>
                                        <td class="px-3 py-3">{{ $metric['unit'] }}{{ $metric['currency'] ? ' · '.$metric['currency'] : '' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            @if (isset($results['pain']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 xl:col-span-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Проблемы и динамика боли') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Клиенты: :clients · Сеансы: :sessions · Оценки до: :before · после: :after', ['clients' => $results['pain']['client_count'], 'sessions' => $results['pain']['session_count'], 'before' => $results['pain']['before_score_count'], 'after' => $results['pain']['after_score_count']]) }}
                        </p>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    @if ($results['pain']['effectiveness_available'] ?? false)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Процент эффективности терапии рассчитан по утверждённому владельцем определению. Сеанс 1 / 2 / 3 — :axis.', ['axis' => $results['pain']['session_axis_label'] ?? 'порядок сеансов конкретной категории проблемы']) }}</p>
                    @else
                        <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">{{ __('Процент эффективности терапии не настроен. Заполните определение и включите метрику в настройках аналитики.') }}</p>
                    @endif
                    @if ($results['pain']['problems'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('За выбранный период структурированные категории не найдены.') }}</p>
                    @else
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2" aria-label="{{ __('Сравнение боли до и после') }}">
                            @foreach ($results['pain']['problems'] as $problem)
                                <div class="space-y-2 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                    <div class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $problem['group'] ? $problem['group'].': ' : '' }}{{ $problem['name'] }}</div>
                                    <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center gap-2"><span class="w-12 shrink-0">{{ __('До') }}</span><div class="h-2 min-w-0 flex-1 rounded-full bg-gray-200 dark:bg-white/10"><div class="h-2 rounded-full bg-amber-500" style="width: {{ $problem['average_before'] === null ? 0 : min(100, max(0, $problem['average_before'] * 10)) }}%"></div></div><span class="w-8 text-right">{{ $problem['average_before'] ?? '—' }}</span></div>
                                        <div class="flex items-center gap-2"><span class="w-12 shrink-0">{{ __('После') }}</span><div class="h-2 min-w-0 flex-1 rounded-full bg-gray-200 dark:bg-white/10"><div class="h-2 rounded-full bg-emerald-500" style="width: {{ $problem['average_after'] === null ? 0 : min(100, max(0, $problem['average_after'] * 10)) }}%"></div></div><span class="w-8 text-right">{{ $problem['average_after'] ?? '—' }}</span></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[760px] text-left text-sm">
                                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2">{{ __('Категория') }}</th>
                                    <th class="px-3 py-2">{{ __('Клиенты') }}</th>
                                    <th class="px-3 py-2">{{ __('Оценки до / после') }}</th>
                                    <th class="px-3 py-2">{{ __('До') }}</th>
                                    <th class="px-3 py-2">{{ __('После') }}</th>
                                    <th class="px-3 py-2">{{ __('Среднее снижение') }}</th>
                                    @if ($results['pain']['effectiveness_available'] ?? false)
                                        <th class="px-3 py-2">{{ __('Снижение боли, %') }}</th>
                                    @endif
                                    <th class="px-3 py-2">{{ __('Сеанс 1 / 2 / 3 после') }}</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($results['pain']['problems'] as $problem)
                                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                        <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                            {{ $problem['group'] ? $problem['group'].': ' : '' }}{{ $problem['name'] }}
                                        </td>
                                        <td class="px-3 py-3">{{ $problem['client_count'] }}</td>
                                        <td class="px-3 py-3">{{ $problem['before_score_count'] }} / {{ $problem['after_score_count'] }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_before'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_after'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_reduction'] ?? '—' }}</td>
                                        @if ($results['pain']['effectiveness_available'] ?? false)
                                            <td class="px-3 py-3">{{ $problem['average_reduction_percent'] === null ? __('Недостаточно данных') : $problem['average_reduction_percent'].'%' }}</td>
                                        @endif
                                        <td class="px-3 py-3">{{ $problem['session_1_after'] ?? '—' }} / {{ $problem['session_2_after'] ?? '—' }} / {{ $problem['session_3_after'] ?? '—' }}</td>
                                        <td class="px-3 py-3"><button type="button" wire:click="showAggregateClients('pain', {{ (int) $problem['tag_id'] }})" class="whitespace-nowrap text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            @if (isset($results['acquisition']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Источники клиентов') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $results['acquisition']['new_clients'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Новых клиентов') }}</p>
                    @php
                        $maxSourceCount = max(1, (int) collect($results['acquisition']['sources'])->max('count'));
                    @endphp
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['acquisition']['sources'] as $source)
                            <li class="space-y-1">
                                <div class="flex justify-between gap-3">
                                    <span class="min-w-0 break-words">{{ $source['label'] }}</span>
                                    <span class="flex flex-wrap items-center justify-end gap-2"><span>{{ __('Клиентов: :count', ['count' => $source['count']]) }}</span>@if (($source['kind'] ?? 'known') === 'unknown')<button type="button" wire:click="showAggregateClients('acquisition_unknown', @js($unknownAggregateValue))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@elseif (($source['kind'] ?? 'known') === 'known' && filled($source['key'] ?? null))<button type="button" wire:click="showAggregateClients('acquisition', @js($source['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@endif</span>
                                </div>
                                <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10" role="img" aria-label="{{ __('Доля источника :source', ['source' => $source['label']]) }}">
                                    <div class="h-2 rounded-full bg-primary-600" style="width: {{ min(100, max(0, $source['count'] / $maxSourceCount * 100)) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (isset($results['scheduling']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Записи и визиты') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        <div><dt class="text-gray-500">{{ __('Записи') }}</dt><dd class="font-semibold">{{ $results['scheduling']['bookings'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Визиты') }}</dt><dd class="font-semibold">{{ $results['scheduling']['visits'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Отмены') }}</dt><dd class="font-semibold">{{ $results['scheduling']['cancellations'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Переносы') }}</dt><dd class="font-semibold">{{ $results['scheduling']['reschedules'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Неявки') }}</dt><dd class="font-semibold">{{ $results['scheduling']['no_shows'] }}</dd></div>
                    </dl>
                </section>
            @endif

            @if (isset($results['finance']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Финансы') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    @if (! ($results['finance']['available'] ?? false))
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Финансовый отчёт недоступен до проверки настроек валюты и сверки.') }}</p>
                    @else
                        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div><dt class="text-gray-500">{{ __('Доход') }}</dt><dd class="font-semibold">{{ $results['finance']['revenue_display'] ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Платежи') }}</dt><dd class="font-semibold">{{ $results['finance']['receipt_count'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Долг') }}</dt><dd class="font-semibold">{{ $results['finance']['debt_display'] ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Средний чек') }}</dt><dd class="font-semibold">{{ $results['finance']['average_receipt_display'] ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('LTV новых клиентов') }}</dt><dd class="font-semibold">{{ $results['finance']['realized_ltv_display'] ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Расходы') }}</dt><dd class="font-semibold">{{ $results['finance']['expenses_display'] ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Движение денежных средств') }}</dt><dd class="font-semibold">{{ $results['finance']['cash_movement_display'] ?? '—' }}</dd></div>
                        </dl>
                        @php
                            $financeRevenue = (float) ($results['finance']['revenue_minor'] ?? 0);
                            $financeExpenses = (float) ($results['finance']['expenses_minor'] ?? 0);
                            $financeMovement = (float) ($results['finance']['cash_movement_minor'] ?? 0);
                            $financeMaximum = max(1, abs($financeRevenue), abs($financeExpenses), abs($financeMovement));
                            $financeFlows = [
                                ['label' => __('Доход'), 'value' => $financeRevenue, 'display' => $results['finance']['revenue_display'] ?? '—', 'color' => 'bg-emerald-500'],
                                ['label' => __('Расходы'), 'value' => $financeExpenses, 'display' => $results['finance']['expenses_display'] ?? '—', 'color' => 'bg-rose-500'],
                                ['label' => __('Движение'), 'value' => $financeMovement, 'display' => $results['finance']['cash_movement_display'] ?? '—', 'color' => 'bg-primary-600'],
                            ];
                        @endphp
                        <div class="mt-5 space-y-3" aria-label="{{ __('Сравнение денежных потоков') }}">
                            @foreach ($financeFlows as $flow)
                                <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <div class="flex justify-between gap-3"><span>{{ $flow['label'] }}</span><span>{{ $flow['display'] }}</span></div>
                                    <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10"><div class="h-2 rounded-full {{ $flow['color'] }}" style="width: {{ min(100, max(0, abs($flow['value']) / $financeMaximum * 100)) }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                        @if ($results['finance']['accounts'] !== [])
                            <ul class="mt-4 space-y-2 text-sm">
                                @foreach ($results['finance']['accounts'] as $account)
                                    <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $account['label'] }}</span><span>{{ $account['movement_display'] ?? $account['movement_minor'] }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </section>
            @endif

            @if (isset($results['feedback']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Удовлетворённость и обратная связь') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div><dt class="text-gray-500">{{ __('Оценок') }}</dt><dd class="font-semibold">{{ $results['feedback']['submission_count'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Средняя оценка') }}</dt><dd class="font-semibold">{{ $results['feedback']['average_score'] ?? '—' }}</dd></div>
                    </dl>
                    @if ($results['feedback']['themes'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Классифицированных тем нет.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2 text-sm">
                            @foreach ($results['feedback']['themes'] as $theme)
                                <li class="flex flex-wrap justify-between gap-3">
                                    <span class="min-w-0 break-words">{{ $theme['group'] ? $theme['group'].': ' : '' }}{{ $theme['name'] }}</span>
                                    <span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $theme['client_count'] }} {{ __('клиентов') }} · {{ $theme['submission_count'] }} {{ __('упоминаний') }} · {{ $theme['average_score'] ?? '—' }}</span><button type="button" wire:click="showAggregateClients('feedback', {{ (int) $theme['id'] }})" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['goals']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Цели и задачи') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    @if ($results['goals']['goals'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Категории задач не заполнены.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2 text-sm">
                            @foreach ($results['goals']['goals'] as $goal)
                                <li class="flex flex-wrap justify-between gap-3">
                                    <span class="min-w-0 break-words">{{ $goal['group'] ? $goal['group'].': ' : '' }}{{ $goal['name'] }}</span>
                                    <span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $goal['client_count'] }} {{ __('клиентов') }} · {{ $goal['task_count'] }} {{ __('задач') }}</span><button type="button" wire:click="showAggregateClients('goals', {{ (int) $goal['id'] }})" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['demographics']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Демография клиентов') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Клиентов: :count', ['count' => $results['demographics']['client_count']]) }}</p>
                    @foreach (['age' => 'Возраст', 'sex' => 'Пол', 'children' => 'Дети и взрослые', 'country' => 'Страна'] as $key => $label)
                        <div class="mt-4">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __($label) }}</h3>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($results['demographics'][$key] as $item)
                                    <li class="space-y-1">
                                        <div class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $item['label'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $item['count'] }} · {{ $item['percent'] }}%</span>@if ($key === 'age' && ($item['unknown'] || $item['key'] !== null))<button type="button" wire:click="showAggregateClients('{{ $item['unknown'] ? 'demographics_age_unknown' : 'demographics_age' }}', @js($item['unknown'] ? $unknownAggregateValue : $item['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@elseif ($key === 'sex' && ($item['unknown'] || $item['key'] !== null))<button type="button" wire:click="showAggregateClients('{{ $item['unknown'] ? 'demographics_sex_unknown' : 'demographics_sex' }}', @js($item['unknown'] ? $unknownAggregateValue : $item['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@elseif ($key === 'children' && ($item['unknown'] || $item['key'] !== null))<button type="button" wire:click="showAggregateClients('{{ $item['unknown'] ? 'demographics_children_unknown' : 'demographics_children' }}', @js($item['unknown'] ? $unknownAggregateValue : $item['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@elseif ($key === 'country' && ($item['unknown'] || $item['key'] !== null))<button type="button" wire:click="showAggregateClients('demographics_country', @js($item['unknown'] ? $unknownAggregateValue : $item['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>@endif</span></div>
                                        <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10" role="img" aria-label="{{ __('Доля категории :category', ['category' => $item['label']]) }}">
                                            <div class="h-2 rounded-full bg-primary-600" style="width: {{ min(100, max(0, $item['percent'])) }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            @endif

            @if (isset($results['services']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Услуги и визиты') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Завершённых визитов: :count', ['count' => $results['services']['visit_count']]) }}</p>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['services']['services'] as $service)
                            <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $service['name'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $service['visit_count'] }} · {{ $service['client_count'] }} {{ __('клиентов') }}</span><button type="button" wire:click="showAggregateClients('services', {{ (int) $service['id'] }})" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span></li>
                        @endforeach
                    </ul>
                    <h3 class="mt-6 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('По специалистам') }}</h3>
                    @if ($results['services']['specialists'] === [])
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Завершённых визитов специалистов за период нет.') }}</p>
                    @else
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($results['services']['specialists'] as $specialist)
                                <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $specialist['name'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $specialist['visit_count'] }} · {{ $specialist['client_count'] }} {{ __('клиентов') }}</span><button type="button" wire:click="showAggregateClients('specialists', {{ (int) $specialist['id'] }})" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['purchases']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Типы покупок') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    @if ($results['purchases']['types'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Покупок за выбранный период нет.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2 text-sm">
                            @foreach ($results['purchases']['types'] as $type)
                                <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $type['label'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $type['count'] }} · {{ $type['client_count'] }} {{ __('клиентов') }}</span><button type="button" wire:click="showAggregateClients('purchases', @js($type['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['booking_statuses']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Статусы записей') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['booking_statuses']['statuses'] as $status)
                            <li class="flex flex-wrap justify-between gap-3"><span>{{ $status['label'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ __('Событий: :count', ['count' => $status['count']]) }}</span><button type="button" wire:click="showAggregateClients('booking_statuses', @js($status['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (isset($results['segments']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Категории клиентов') }}</h2>
                        <button type="button" wire:click="showCohortClients" class="text-sm text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Клиентов: :count', ['count' => $results['segments']['client_count']]) }}</p>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['segments']['segments'] as $segment)
                            <li class="space-y-1">
                                <div class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $segment['label'] }}</span><span class="flex flex-wrap items-center justify-end gap-2"><span>{{ $segment['count'] }} · {{ $segment['percent'] }}%</span><button type="button" wire:click="showAggregateClients('segments', @js($segment['key']))" class="text-primary-600 hover:underline">{{ __('Показать клиентов') }}</button></span></div>
                                <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10" role="img" aria-label="{{ __('Доля категории :category', ['category' => $segment['label']]) }}">
                                    <div class="h-2 rounded-full bg-primary-600" style="width: {{ min(100, max(0, $segment['percent'])) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
