@php
    $schemaVersion = (string) ($metadata['schema_version'] ?? '');
    $schemaLabel = str_ends_with($schemaVersion, '_v1') ? __('Служебный формат v1') : __('Служебный формат');
    $conversationStateLabel = match (data_get($metadata, 'conversation.state')) {
        'ai_active' => __('AI отвечает'),
        'human_handoff' => __('Диалог ведёт специалист'),
        default => __('Состояние не определено'),
    };
    $turnStatusLabels = [
        'assembling' => __('Собирается'),
        'pending' => __('Ожидает обработки'),
        'processing' => __('Обрабатывается'),
        'completed' => __('Завершён'),
        'failed' => __('Не выполнен'),
        'escalated' => __('Передан специалисту'),
        'paused' => __('Приостановлен'),
        'cancelled' => __('Отменён'),
    ];
    $transportLabels = [
        'portal' => __('Портал'),
        'telegram' => 'Telegram',
    ];
    $handoffReasonLabels = [
        'human_requested' => __('Клиент попросил специалиста'),
        'out_of_scope' => __('Вопрос требует специалиста'),
        'urgent_safety_concern' => __('Требуется внимание специалиста'),
        'repeated_execution_failure' => __('AI временно недоступен'),
        'other' => __('Другое обращение к специалисту'),
    ];
    $failureCodeLabels = [
        'not_configured' => __('AI не настроен'),
        'provider_disabled' => __('AI отключён'),
        'budget_unavailable' => __('Лимит AI исчерпан'),
        'provider_unavailable' => __('AI временно недоступен'),
        'invalid_output' => __('Ответ AI не прошёл проверку'),
        'retrieval_failure' => __('Не удалось получить данные для ответа'),
        'queue_failure' => __('Не удалось обработать запрос'),
        'delivery_failure' => __('Не удалось доставить сообщение'),
        'rate_limited' => __('Слишком много запросов'),
        'image_unavailable' => __('Изображение недоступно'),
        'document_unavailable' => __('Документ недоступен'),
        'input_limit_exceeded' => __('Сообщение слишком большое'),
        'media_group_incomplete' => __('Не все файлы получены'),
        'execution_deadline_exceeded' => __('Время обработки истекло'),
    ];
    $deliveryStatusLabels = [
        'pending' => __('Ожидает отправки'),
        'processing' => __('Отправляется'),
        'delivered' => __('Доставлено'),
        'failed' => __('Не доставлено'),
        'uncertain' => __('Доставка не подтверждена'),
    ];
@endphp

<div class="max-w-full space-y-5 text-sm">
    <dl class="grid min-w-0 gap-3 sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Формат данных') }}</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ $schemaLabel }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Связанный объект') }}</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ __('Клиент') }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Состояние диалога') }}</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ $conversationStateLabel }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Версия контекста') }}</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ data_get($metadata, 'conversation.context_epoch', '—') }}</dd>
        </div>
    </dl>

    <div>
        <h3 class="font-semibold text-gray-950 dark:text-white">{{ __('Обработка диалога') }}</h3>
        <div class="mt-3 max-h-[60vh] space-y-3 overflow-y-auto pr-1">
            @forelse (($metadata['turns'] ?? []) as $turn)
                @php
                    $deliveryLabel = collect(is_array($turn['delivery'] ?? null) ? $turn['delivery'] : [])
                        ->map(static function (mixed $delivery) use ($deliveryStatusLabels): string {
                            if (! is_array($delivery)) {
                                return __('Статус не определён');
                            }

                            $label = $deliveryStatusLabels[(string) ($delivery['status'] ?? '')] ?? __('Статус не определён');
                            $chunkCount = (int) ($delivery['chunk_count'] ?? 1);

                            return $chunkCount > 1 ? __(':label (:count части)', ['label' => $label, 'count' => $chunkCount]) : $label;
                        })
                        ->implode(', ');
                @endphp
                <article class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <dl class="grid min-w-0 gap-2 sm:grid-cols-2">
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Ход обработки') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['sequence'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Состояние') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turnStatusLabels[(string) ($turn['status'] ?? '')] ?? __('Состояние не определено') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Канал') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $transportLabels[strtolower((string) ($turn['origin_transport'] ?? ''))] ?? (filled($turn['origin_transport'] ?? null) ? __('Другой канал') : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Передача специалисту') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $handoffReasonLabels[(string) ($turn['handoff_reason'] ?? '')] ?? (filled($turn['handoff_reason'] ?? null) ? __('Причина не указана') : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Причина сбоя') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $failureCodeLabels[(string) ($turn['failure_code'] ?? '')] ?? (filled($turn['failure_code'] ?? null) ? __('Сбой обработки') : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">{{ __('Доставка') }}</dt><dd class="break-words text-gray-950 dark:text-white">{{ $deliveryLabel !== '' ? $deliveryLabel : '—' }}</dd></div>
                    </dl>
                    @if (is_array($turn['ai_observability'] ?? null))
                        <details class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ __('Данные запуска AI') }}</summary>
                            <pre class="mt-2 max-w-full overflow-x-auto whitespace-pre-wrap break-words text-xs text-gray-600 dark:text-gray-300">{{ json_encode($turn['ai_observability'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </article>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('Запусков пока нет.') }}</p>
            @endforelse
        </div>
    </div>
</div>
