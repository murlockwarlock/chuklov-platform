<x-filament-panels::page>
    {{ $this->content }}

    @if ($results === [])
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
            {{ __('Выберите показатели и нажмите «Анализировать».') }}
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            @if (isset($results['pain']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 xl:col-span-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Проблемы и динамика боли') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Клиенты: :clients · Сеансы: :sessions', ['clients' => $results['pain']['client_count'], 'sessions' => $results['pain']['session_count']]) }}
                        </p>
                    </div>
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
                                    <th class="px-3 py-2">{{ __('До') }}</th>
                                    <th class="px-3 py-2">{{ __('После') }}</th>
                                    <th class="px-3 py-2">{{ __('Среднее снижение') }}</th>
                                    <th class="px-3 py-2">{{ __('Сеанс 1 / 2 / 3 после') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($results['pain']['problems'] as $problem)
                                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                        <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                            {{ $problem['group'] ? $problem['group'].': ' : '' }}{{ $problem['name'] }}
                                        </td>
                                        <td class="px-3 py-3">{{ $problem['client_count'] }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_before'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_after'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $problem['average_reduction'] ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $problem['session_1_after'] ?? '—' }} / {{ $problem['session_2_after'] ?? '—' }} / {{ $problem['session_3_after'] ?? '—' }}</td>
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
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Источники клиентов') }}</h2>
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
                                    <span>{{ $source['count'] }}</span>
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
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Записи и визиты') }}</h2>
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
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Финансы') }}</h2>
                    @if (! ($results['finance']['available'] ?? false))
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Финансовый отчёт недоступен до проверки настроек валюты и сверки.') }}</p>
                    @else
                        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div><dt class="text-gray-500">{{ __('Доход') }}</dt><dd class="font-semibold">{{ $results['finance']['revenue_minor'] }} {{ $results['finance']['currency'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Платежи') }}</dt><dd class="font-semibold">{{ $results['finance']['receipt_count'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Долг') }}</dt><dd class="font-semibold">{{ $results['finance']['debt_minor'] }} {{ $results['finance']['currency'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('LTV') }}</dt><dd class="font-semibold">{{ $results['finance']['realized_ltv_minor'] ?? '—' }} {{ $results['finance']['currency'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Расходы') }}</dt><dd class="font-semibold">{{ $results['finance']['expenses_minor'] ?? '—' }} {{ $results['finance']['currency'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('Движение денежных средств') }}</dt><dd class="font-semibold">{{ $results['finance']['cash_movement_minor'] ?? '—' }} {{ $results['finance']['currency'] }}</dd></div>
                        </dl>
                        @php
                            $financeRevenue = (float) ($results['finance']['revenue_minor'] ?? 0);
                            $financeExpenses = (float) ($results['finance']['expenses_minor'] ?? 0);
                            $financeMovement = (float) ($results['finance']['cash_movement_minor'] ?? 0);
                            $financeMaximum = max(1, abs($financeRevenue), abs($financeExpenses), abs($financeMovement));
                            $financeFlows = [
                                ['label' => __('Доход'), 'value' => $financeRevenue, 'color' => 'bg-emerald-500'],
                                ['label' => __('Расходы'), 'value' => $financeExpenses, 'color' => 'bg-rose-500'],
                                ['label' => __('Движение'), 'value' => $financeMovement, 'color' => 'bg-primary-600'],
                            ];
                        @endphp
                        <div class="mt-5 space-y-3" aria-label="{{ __('Сравнение денежных потоков') }}">
                            @foreach ($financeFlows as $flow)
                                <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <div class="flex justify-between gap-3"><span>{{ $flow['label'] }}</span><span>{{ $flow['value'] }} {{ $results['finance']['currency'] }}</span></div>
                                    <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10"><div class="h-2 rounded-full {{ $flow['color'] }}" style="width: {{ min(100, max(0, abs($flow['value']) / $financeMaximum * 100)) }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                        @if ($results['finance']['accounts'] !== [])
                            <ul class="mt-4 space-y-2 text-sm">
                                @foreach ($results['finance']['accounts'] as $account)
                                    <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $account['label'] }}</span><span>{{ $account['movement_minor'] }} {{ $results['finance']['currency'] }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </section>
            @endif

            @if (isset($results['feedback']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Удовлетворённость и обратная связь') }}</h2>
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
                                    <span>{{ $theme['client_count'] }} {{ __('клиентов') }} · {{ $theme['average_score'] ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['goals']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Цели и задачи') }}</h2>
                    @if ($results['goals']['goals'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Категории задач не заполнены.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2 text-sm">
                            @foreach ($results['goals']['goals'] as $goal)
                                <li class="flex flex-wrap justify-between gap-3">
                                    <span class="min-w-0 break-words">{{ $goal['group'] ? $goal['group'].': ' : '' }}{{ $goal['name'] }}</span>
                                    <span>{{ $goal['client_count'] }} {{ __('клиентов') }} · {{ $goal['task_count'] }} {{ __('задач') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['demographics']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Демография клиентов') }}</h2>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Клиентов: :count', ['count' => $results['demographics']['client_count']]) }}</p>
                    @foreach (['age' => 'Возраст', 'sex' => 'Пол', 'children' => 'Дети и взрослые', 'country' => 'Страна'] as $key => $label)
                        <div class="mt-4">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __($label) }}</h3>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($results['demographics'][$key] as $item)
                                    <li class="space-y-1">
                                        <div class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $item['label'] }}</span><span>{{ $item['count'] }} · {{ $item['percent'] }}%</span></div>
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
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Услуги и визиты') }}</h2>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Завершённых визитов: :count', ['count' => $results['services']['visit_count']]) }}</p>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['services']['services'] as $service)
                            <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $service['name'] }}</span><span>{{ $service['visit_count'] }} · {{ $service['client_count'] }} {{ __('клиентов') }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (isset($results['purchases']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Типы покупок') }}</h2>
                    @if ($results['purchases']['types'] === [])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Покупок за выбранный период нет.') }}</p>
                    @else
                        <ul class="mt-4 space-y-2 text-sm">
                            @foreach ($results['purchases']['types'] as $type)
                                <li class="flex flex-wrap justify-between gap-3"><span class="min-w-0 break-words">{{ $type['label'] }}</span><span>{{ $type['count'] }} · {{ $type['client_count'] }} {{ __('клиентов') }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($results['booking_statuses']))
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Статусы записей') }}</h2>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($results['booking_statuses']['statuses'] as $status)
                            <li class="flex flex-wrap justify-between gap-3"><span>{{ $status['label'] }}</span><span>{{ $status['count'] }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
