<div class="max-w-full space-y-5 text-sm">
    <dl class="grid min-w-0 gap-3 sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Схема</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ $metadata['schema_version'] ?? '—' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Идентификатор</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ data_get($metadata, 'identity.label', '—') }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Состояние диалога</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ data_get($metadata, 'conversation.state', '—') }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500 dark:text-gray-400">Эпоха контекста</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ data_get($metadata, 'conversation.context_epoch', '—') }}</dd>
        </div>
    </dl>

    <div>
        <h3 class="font-semibold text-gray-950 dark:text-white">Запуски Companion</h3>
        <div class="mt-3 max-h-[60vh] space-y-3 overflow-y-auto pr-1">
            @forelse (($metadata['turns'] ?? []) as $turn)
                <article class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <dl class="grid min-w-0 gap-2 sm:grid-cols-2">
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Последовательность</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['sequence'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Статус</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['status'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Канал</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['origin_transport'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Причина handoff</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['handoff_reason'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Код сбоя</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['failure_code'] ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-gray-500 dark:text-gray-400">Доставка</dt><dd class="break-words text-gray-950 dark:text-white">{{ $turn['delivery'] === [] ? '—' : json_encode($turn['delivery'], JSON_UNESCAPED_UNICODE) }}</dd></div>
                    </dl>
                    @if (is_array($turn['ai_observability'] ?? null))
                        <details class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Данные запуска AI</summary>
                            <pre class="mt-2 max-w-full overflow-x-auto whitespace-pre-wrap break-words text-xs text-gray-600 dark:text-gray-300">{{ json_encode($turn['ai_observability'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </article>
            @empty
                <p class="text-gray-500 dark:text-gray-400">Технических запусков пока нет.</p>
            @endforelse
        </div>
    </div>
</div>
