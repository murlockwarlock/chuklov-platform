@php
    $schemaVersion = (string) ($metadata['schema_version'] ?? '');
    $schemaLabel = str_ends_with($schemaVersion, '_v1') ? 'Служебный формат v1' : 'Служебный формат';
    $conversationStateLabel = match (data_get($metadata, 'conversation.state')) {
        'ai_active' => 'AI отвечает',
        'human_handoff' => 'Диалог ведёт специалист',
        default => 'Состояние не определено',
    };
    $turnStatusLabels = [
        'assembling' => 'Собирается',
        'pending' => 'Ожидает обработки',
        'processing' => 'Обрабатывается',
        'completed' => 'Завершён',
        'failed' => 'Не выполнен',
        'escalated' => 'Передан специалисту',
        'paused' => 'Приостановлен',
        'cancelled' => 'Отменён',
    ];
    $transportLabels = [
        'portal' => 'Портал',
        'telegram' => 'Telegram',
    ];
    $handoffReasonLabels = [
        'human_requested' => 'Клиент попросил специалиста',
        'out_of_scope' => 'Вопрос требует специалиста',
        'urgent_safety_concern' => 'Требуется внимание специалиста',
        'repeated_execution_failure' => 'AI временно недоступен',
        'other' => 'Другое обращение к специалисту',
    ];
    $failureCodeLabels = [
        'not_configured' => 'AI не настроен',
        'provider_disabled' => 'AI отключён',
        'budget_unavailable' => 'Лимит AI исчерпан',
        'provider_unavailable' => 'AI временно недоступен',
        'invalid_output' => 'Ответ AI не прошёл проверку',
        'retrieval_failure' => 'Не удалось получить данные для ответа',
        'queue_failure' => 'Не удалось обработать запрос',
        'delivery_failure' => 'Не удалось доставить сообщение',
        'rate_limited' => 'Слишком много запросов',
        'image_unavailable' => 'Изображение недоступно',
        'document_unavailable' => 'Документ недоступен',
        'input_limit_exceeded' => 'Сообщение слишком большое',
        'media_group_incomplete' => 'Не все файлы получены',
        'execution_deadline_exceeded' => 'Время обработки истекло',
    ];
    $deliveryStatusLabels = [
        'pending' => 'Ожидает отправки',
        'processing' => 'Отправляется',
        'delivered' => 'Доставлено',
        'failed' => 'Не доставлено',
        'uncertain' => 'Доставка не подтверждена',
    ];
@endphp

<div class="max-w-full space-y-5 text-sm">
    <dl class="grid min-w-0 gap-3 sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Формат данных</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ $schemaLabel }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Связанный объект</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">Клиент</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Состояние диалога</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ $conversationStateLabel }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Версия контекста</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ data_get($metadata, 'conversation.context_epoch', '—') }}</dd>
        </div>
    </dl>

    <div>
        <h3 class="font-semibold text-gray-950 dark:text-white">Обработка диалога</h3>
        <div class="mt-3 max-h-[60vh] space-y-3 overflow-y-auto pr-1">
            @forelse (($metadata['turns'] ?? []) as $turn)
                @php
                    $deliveryLabel = collect(is_array($turn['delivery'] ?? null) ? $turn['delivery'] : [])
                        ->map(static function (mixed $delivery) use ($deliveryStatusLabels): string {
                            if (! is_array($delivery)) {
                                return 'Статус не определён';
                            }

                            $label = $deliveryStatusLabels[(string) ($delivery['status'] ?? '')] ?? 'Статус не определён';
                            $chunkCount = (int) ($delivery['chunk_count'] ?? 1);

                            return $chunkCount > 1 ? $label.' ('.$chunkCount.' части)' : $label;
                        })
                        ->implode(', ');
                @endphp
                <article class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <dl class="grid min-w-0 gap-2 sm:grid-cols-2">
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Ход обработки</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['sequence'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Состояние</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turnStatusLabels[(string) ($turn['status'] ?? '')] ?? 'Состояние не определено' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Канал</dt><dd class="break-words text-gray-950 dark:text-white">{{ $transportLabels[strtolower((string) ($turn['origin_transport'] ?? ''))] ?? (filled($turn['origin_transport'] ?? null) ? 'Другой канал' : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Передача специалисту</dt><dd class="break-words text-gray-950 dark:text-white">{{ $handoffReasonLabels[(string) ($turn['handoff_reason'] ?? '')] ?? (filled($turn['handoff_reason'] ?? null) ? 'Причина не указана' : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Причина сбоя</dt><dd class="break-words text-gray-950 dark:text-white">{{ $failureCodeLabels[(string) ($turn['failure_code'] ?? '')] ?? (filled($turn['failure_code'] ?? null) ? 'Сбой обработки' : '—') }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Доставка</dt><dd class="break-words text-gray-950 dark:text-white">{{ $deliveryLabel !== '' ? $deliveryLabel : '—' }}</dd></div>
                    </dl>
                    @if (is_array($turn['ai_observability'] ?? null))
                        <details class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Данные запуска AI</summary>
                            <pre class="mt-2 max-w-full overflow-x-auto whitespace-pre-wrap break-words text-xs text-gray-600 dark:text-gray-300">{{ json_encode($turn['ai_observability'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </article>
            @empty
                <p class="text-gray-500 dark:text-gray-400">Запусков пока нет.</p>
            @endforelse
        </div>
    </div>
</div>
