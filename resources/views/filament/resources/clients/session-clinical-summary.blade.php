@php
    $synthesis = $summary['states']['synthesis'] ?? null;
    $badgeClasses = [
        'success' => 'bg-success-50 text-success-700 dark:bg-success-950/30 dark:text-success-200',
        'info' => 'bg-info-50 text-info-700 dark:bg-info-950/30 dark:text-info-200',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-950/30 dark:text-warning-200',
        'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-950/30 dark:text-danger-200',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
    ];
@endphp

<div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
    @if ($synthesis)
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-gray-600 dark:text-gray-300">Статус:</span>
            <span class="inline-flex max-w-full rounded-full px-2 py-1 text-xs font-medium {{ $badgeClasses[$synthesis['color']] ?? $badgeClasses['gray'] }}">{{ $synthesis['state'] }}</span>
        </div>
    @endif

    @if ($summary['synthesisPreview'] ?? null)
        @if ($summary['synthesisPreviewAt'] ?? null)
            <p class="break-words text-xs text-gray-500 dark:text-gray-400">Последнее проверенное резюме: {{ $summary['synthesisPreviewAt'] }}</p>
        @endif
        <p class="whitespace-pre-line break-words leading-6">{{ $summary['synthesisPreview'] }}</p>
    @elseif (($synthesis['state'] ?? null) === 'Требует проверки')
        <p class="break-words text-sm text-gray-600 dark:text-gray-300">Результат ожидает проверки специалиста.</p>
    @elseif (($synthesis['state'] ?? null) === 'Отклонено')
        <p class="break-words text-sm text-gray-600 dark:text-gray-300">Результат отклонён и не используется как подтверждённый источник.</p>
    @elseif (($synthesis['state'] ?? null) === 'Нет')
        <p class="break-words text-sm text-gray-500 dark:text-gray-400">Клиническое резюме ещё не создано.</p>
    @endif

    @if ($clinicalAiUrl)
        <x-filament::button tag="a" href="{{ $clinicalAiUrl }}" size="sm" color="gray" outlined>Открыть Клинический AI</x-filament::button>
    @endif
</div>
