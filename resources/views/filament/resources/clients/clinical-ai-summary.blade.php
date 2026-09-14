@php
    $badgeClasses = [
        'success' => 'bg-success-50 text-success-700 dark:bg-success-950/30 dark:text-success-200',
        'info' => 'bg-info-50 text-info-700 dark:bg-info-950/30 dark:text-info-200',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-950/30 dark:text-warning-200',
        'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-950/30 dark:text-danger-200',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
    ];
    $stateRows = [
        $summary['states']['documents'],
        $summary['states']['posture'],
        $summary['states']['synthesis'],
    ];
@endphp

<span class="block max-w-5xl space-y-3 text-sm text-gray-700 dark:text-gray-200">
    <span class="block max-w-4xl break-words leading-6">{{ $summary['explanation'] }}</span>

    <span class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        @foreach ($stateRows as $state)
            <span class="flex min-w-0 flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                <span class="min-w-0 break-words text-xs font-medium text-gray-600 dark:text-gray-300">{{ $state['label'] }}</span>
                <span class="min-w-0">
                    <span class="inline-flex max-w-full rounded-full px-2 py-1 text-xs font-medium {{ $badgeClasses[$state['color']] ?? $badgeClasses['gray'] }}">{{ $state['state'] }}</span>
                    @if ($state['label'] === 'Клиническое резюме' && $state['lastReadyAt'])
                        <span class="mt-1 block break-words text-xs text-gray-500 dark:text-gray-400">Последний проверенный результат: {{ $state['lastReadyAt'] }}</span>
                    @endif
                </span>
            </span>
        @endforeach
    </span>

    <span class="block rounded-xl border border-gray-200 px-3 py-2.5 dark:border-white/10">
        <span class="block text-xs font-semibold text-gray-700 dark:text-gray-200">Источники клинического резюме</span>
        <span class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
            @foreach ($summary['readiness'] as $source)
                <span class="min-w-0 break-words text-xs {{ $source['available'] ? 'text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $source['label'] }}: {{ $source['availability'] }}
                </span>
            @endforeach
        </span>
    </span>
</span>
