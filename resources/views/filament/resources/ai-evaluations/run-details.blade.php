<div class="space-y-5">
    @php
        $metrics = is_array($metrics ?? null)
            ? $metrics
            : (is_array($run->metrics_payload) ? $run->metrics_payload : []);
        $hasOperationalMetrics = is_array($metrics['latency'] ?? null);
        $tokens = is_array($metrics['tokens'] ?? null) ? $metrics['tokens'] : null;
        $ragMetrics = is_array($metrics['rag'] ?? null) ? $metrics['rag'] : null;
        $cases = is_array($run->results_payload['cases'] ?? null) ? $run->results_payload['cases'] : [];
        $categoryLabels = [
            'execution' => __('выполнение AI'),
            'assertion' => __('проверка содержания'),
            'schema' => __('структура ответа'),
            'rag' => __('источник базы знаний'),
            'judge' => __('дополнительная оценка'),
        ];
    @endphp

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">{{ __('Результат') }}</div>
            <div class="mt-1 text-lg font-semibold">{{ number_format((float) $run->pass_percentage, 2, app()->getLocale() === 'ru' ? ',' : '.', '') }}%</div>
            <div class="text-xs text-gray-500">{{ __(':passed из :total примеров', ['passed' => $run->passed_cases, 'total' => $run->total_cases]) }}</div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">{{ __('Среднее время') }}</div>
            <div class="mt-1 text-lg font-semibold">{{ $hasOperationalMetrics ? ($run->average_latency_ms > 1000 ? number_format($run->average_latency_ms / 1000, 2, app()->getLocale() === 'ru' ? ',' : '.', '').' '.__('с') : $run->average_latency_ms.' '.__('мс')) : __('нет данных') }}</div>
            <div class="text-xs text-gray-500">{{ __('Ошибок выполнения: :count', ['count' => $hasOperationalMetrics ? $run->execution_error_count : __('нет данных')]) }}</div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">{{ __('Расчётная стоимость Chuklov') }}</div>
            <div class="mt-1 text-lg font-semibold">
                @if (($metrics['cost']['estimated_currency_unknown_count'] ?? 0) > 0)
                    {{ __('нет данных: валюта не указана') }}
                @else
                    @forelse (($metrics['cost']['estimated_by_currency'] ?? []) as $currency => $minorUnits)
                        {{ $currency }} {{ number_format($minorUnits / 100, 2, app()->getLocale() === 'ru' ? ',' : '.', ' ') }}
                    @empty
                        {{ __('нет данных') }}
                    @endforelse
                @endif
            </div>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <div class="text-xs text-gray-500">{{ __('Стоимость AI-сервиса') }}</div>
            <div class="mt-1 text-lg font-semibold">
                @if (($metrics['cost']['provider_reported_unknown_count'] ?? $metrics['cost']['provider_reported_currency_unknown_count'] ?? 0) > 0)
                    {{ __('нет данных: стоимость не сообщена или валюта неизвестна') }}
                @else
                    @forelse (($metrics['cost']['provider_reported_by_currency'] ?? []) as $currency => $minorUnits)
                        {{ $currency }} {{ number_format($minorUnits / 100, 2, app()->getLocale() === 'ru' ? ',' : '.', ' ') }}
                    @empty
                        {{ __('нет данных') }}
                    @endforelse
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">{{ __('Использование токенов') }}</div>
            @if ($tokens !== null)
                <div class="mt-1 text-gray-600 dark:text-gray-300">
                    {{ __('Вход: :input · выход: :output · всего: :total', ['input' => (int) ($tokens['prompt_tokens'] ?? 0), 'output' => (int) ($tokens['completion_tokens'] ?? 0), 'total' => (int) ($tokens['total_tokens'] ?? 0)]) }}
                </div>
            @else
                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ __('нет данных') }}</div>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">{{ __('История поиска по базе знаний') }}</div>
            @if ($ragMetrics !== null)
                <div class="mt-1 text-gray-600 dark:text-gray-300">
                    {{ __('Проверено: :checked · пройдено: :passed · не пройдено: :failed · не проверено: :unchecked', ['checked' => (int) ($ragMetrics['checked_cases'] ?? 0), 'passed' => (int) ($ragMetrics['passed_cases'] ?? 0), 'failed' => (int) ($ragMetrics['failed_cases'] ?? 0), 'unchecked' => (int) ($ragMetrics['unchecked_cases'] ?? 0)]) }}
                </div>
                <div class="mt-1 text-xs text-gray-500">{{ __('Показатели отражают сохранённые результаты поиска, а не доказательство смысловой опоры ответа.') }}</div>
            @else
                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ __('нет данных') }}</div>
            @endif
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold">{{ __('Примеры, которые требуют внимания') }}</h3>
        <div class="mt-2 divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
            @forelse ($cases as $case)
                <div class="p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $case['case_name'] ?? __('Пример без названия') }}</span>
                        <span class="text-sm {{ ($case['passed'] ?? false) ? 'text-success-600' : 'text-danger-600' }}">
                            {{ ($case['passed'] ?? false) ? __('Пройден') : __('Требует внимания') }}
                        </span>
                    </div>
                    @if (is_array($case['rag'] ?? null) && ($case['rag']['checks_present'] ?? false))
                        <div class="mt-1 text-xs text-gray-500">
                            {{ __('Сохранённая история поиска: ссылок :count.', ['count' => (int) ($case['rag']['reference_count'] ?? 0)]) }}
                        </div>
                    @endif
                    @if (! ($case['passed'] ?? false))
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ $case['failure_explanation'] ?? __('Проверка не пройдена.') }}
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ __('Категория: :category', ['category' => $categoryLabels[$case['failure_category'] ?? ''] ?? __('проверка')]) }}
                        </div>
                        @php
                            $actualOutput = $case['actual_output'] ?? null;
                            $decodedOutput = is_string($actualOutput) ? json_decode($actualOutput, true) : null;
                            $actualDisplay = is_array($decodedOutput)
                                ? json_encode($decodedOutput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                                : ($actualOutput ?: __('Ответ не получен.'));
                        @endphp
                        <dl class="mt-3 space-y-3 text-sm">
                            <div>
                                <dt class="font-medium">{{ __('Ввод') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ json_encode($case['test_inputs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">{{ __('Ожидаемое поведение') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ json_encode($case['expected_assertions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">{{ __('Фактический ответ') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ $actualDisplay }}</dd>
                            </div>
                        </dl>
                    @endif
                </div>
            @empty
                <div class="p-4 text-sm text-gray-500">{{ __('Сведения о примерах не сохранены.') }}</div>
            @endforelse
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">{{ __('Надёжность') }}</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $hasOperationalMetrics ? __('Повторных попыток: :retries · переходов на резерв: :failovers', ['retries' => $run->retry_count, 'failovers' => $run->failover_count]) : __('нет данных') }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="font-semibold">{{ __('Проверка специалистом') }}</div>
            @if (isset($metrics['human_review']) && is_array($metrics['human_review']))
                <div class="mt-1 text-gray-600 dark:text-gray-300">
                    {{ __('Принято: :accepted · отредактировано и принято: :edited · отклонено: :rejected', ['accepted' => $metrics['human_review']['accepted_count'] ?? 0, 'edited' => $metrics['human_review']['edited_and_accepted_count'] ?? 0, 'rejected' => $metrics['human_review']['rejected_count'] ?? 0]) }}
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    {{ __('Доли: :accepted% · :edited% · :rejected%', ['accepted' => number_format((float) ($metrics['human_review']['accepted_rate'] ?? 0), 2, app()->getLocale() === 'ru' ? ',' : '.', ''), 'edited' => number_format((float) ($metrics['human_review']['edited_and_accepted_rate'] ?? 0), 2, app()->getLocale() === 'ru' ? ',' : '.', ''), 'rejected' => number_format((float) ($metrics['human_review']['rejected_rate'] ?? 0), 2, app()->getLocale() === 'ru' ? ',' : '.', '')]) }}
                </div>
            @else
                <div class="mt-1 text-gray-600 dark:text-gray-300">{{ __('нет данных') }}</div>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
        <span class="font-semibold">{{ __('Дополнительная оценка:') }}</span>
        {{ $metrics['judge']['label'] ?? __('не настроена') }}.
    </div>

    <p class="text-xs text-gray-500">{{ __('Подробности примеров показаны выше. Дополнительные сведения доступны по отдельному разрешению.') }}</p>
</div>
