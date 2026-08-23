<div class="space-y-5">
    @php
        $metrics = is_array($run->metrics_payload) ? $run->metrics_payload : [];
        $cases = is_array($run->results_payload['cases'] ?? null) ? $run->results_payload['cases'] : [];
        $categoryLabels = [
            'execution' => 'выполнение AI',
            'assertion' => 'проверка содержания',
            'schema' => 'структура ответа',
            'rag' => 'источник базы знаний',
            'judge' => 'дополнительная оценка',
        ];
    @endphp

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">Результат</div>
            <div class="mt-1 text-lg font-semibold">{{ number_format((float) $run->pass_percentage, 2, ',', '') }}%</div>
            <div class="text-xs text-gray-500">{{ $run->passed_cases }} из {{ $run->total_cases }} примеров</div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">Среднее время</div>
            <div class="mt-1 text-lg font-semibold">{{ $run->average_latency_ms > 1000 ? number_format($run->average_latency_ms / 1000, 2, ',', '').' с' : $run->average_latency_ms.' мс' }}</div>
            <div class="text-xs text-gray-500">Ошибок выполнения: {{ $run->execution_error_count }}</div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">Расчётная стоимость Chuklov</div>
            <div class="mt-1 text-lg font-semibold">
                @forelse (($metrics['cost']['estimated_by_currency'] ?? []) as $currency => $minorUnits)
                    {{ $currency }} {{ number_format($minorUnits / 100, 2, ',', ' ') }}
                @empty
                    нет данных
                @endforelse
            </div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">Стоимость от провайдера</div>
            <div class="mt-1 text-lg font-semibold">
                @forelse (($metrics['cost']['provider_reported_by_currency'] ?? []) as $currency => $minorUnits)
                    {{ $currency }} {{ number_format($minorUnits / 100, 2, ',', ' ') }}
                @empty
                    нет данных
                @endforelse
            </div>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold">Примеры, которые требуют внимания</h3>
        <div class="mt-2 divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
            @forelse ($cases as $case)
                <div class="p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $case['case_name'] ?? 'Пример без названия' }}</span>
                        <span class="text-sm {{ ($case['passed'] ?? false) ? 'text-success-600' : 'text-danger-600' }}">
                            {{ ($case['passed'] ?? false) ? 'Пройден' : 'Требует внимания' }}
                        </span>
                    </div>
                    @if (! ($case['passed'] ?? false))
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ $case['failure_explanation'] ?? 'Проверка не пройдена.' }}
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            Категория: {{ $categoryLabels[$case['failure_category'] ?? ''] ?? 'проверка' }}
                        </div>
                    @endif
                </div>
            @empty
                <div class="p-4 text-sm text-gray-500">Сведения о примерах не сохранены.</div>
            @endforelse
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">Надёжность</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">Повторных попыток: {{ $run->retry_count }} · переходов на резерв: {{ $run->failover_count }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">Проверка специалистом</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">
                Принято: {{ $metrics['human_review']['accepted_count'] ?? 0 }} · отредактировано и принято: {{ $metrics['human_review']['edited_and_accepted_count'] ?? 0 }} · отклонено: {{ $metrics['human_review']['rejected_count'] ?? 0 }}
            </div>
            <div class="mt-1 text-xs text-gray-500">
                Доли: {{ number_format((float) ($metrics['human_review']['accepted_rate'] ?? 0), 2, ',', '') }}% · {{ number_format((float) ($metrics['human_review']['edited_and_accepted_rate'] ?? 0), 2, ',', '') }}% · {{ number_format((float) ($metrics['human_review']['rejected_rate'] ?? 0), 2, ',', '') }}%
            </div>
        </div>
    </div>

    <p class="text-xs text-gray-500">Текст запросов и ответов здесь не показывается. Защищённый след AI доступен только через отдельное разрешение.</p>
</div>
