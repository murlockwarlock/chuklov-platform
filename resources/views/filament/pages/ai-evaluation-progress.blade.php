<x-filament-panels::page>
    @php
        $status = $progress['status'] ?? 'expired';
        $running = in_array($status, ['queued', 'running'], true);
        $processed = (int) ($progress['processed'] ?? 0);
        $total = (int) ($progress['total'] ?? 0);
        $failedCases = is_array($progress['failed_cases'] ?? null) ? $progress['failed_cases'] : [];
        $percentage = $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0;
    @endphp

    <div @if ($running) wire:poll.2s="refreshProgress" @endif class="space-y-6">
        @if ($running)
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-900 dark:bg-primary-950/30">
                <h2 class="text-lg font-semibold text-primary-950 dark:text-primary-100">Проверка запущена</h2>
                <p class="mt-2 text-sm text-primary-800 dark:text-primary-200">Выполнено {{ $processed }} из {{ $total }}</p>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-primary-100 dark:bg-primary-900">
                    <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $percentage }}%"></div>
                </div>
            </div>
        @elseif ($status === 'completed')
            <div class="rounded-xl border border-success-200 bg-success-50 p-5 dark:border-success-900 dark:bg-success-950/30">
                <h2 class="text-lg font-semibold text-success-950 dark:text-success-100">Проверка завершена</h2>
                <p class="mt-2 text-sm text-success-800 dark:text-success-200">{{ (int) ($progress['passed'] ?? 0) }} из {{ $total }} пройдено</p>
                @if ($failedCases !== [])
                    <p class="mt-2 font-medium text-warning-800 dark:text-warning-200">{{ count($failedCases) }} {{ count($failedCases) === 1 ? 'проверка не пройдена' : 'проверок не пройдено' }}</p>
                @endif
            </div>
        @else
            <div class="rounded-xl border border-warning-200 bg-warning-50 p-5 text-sm text-warning-800 dark:border-warning-900 dark:bg-warning-950/30 dark:text-warning-200">
                {{ $progress['message'] ?? 'Проверка недоступна.' }}
            </div>
        @endif

        @if ($failedCases !== [])
            <x-filament::section heading="Проверки, требующие внимания">
                <div class="space-y-4">
                    @foreach ($failedCases as $case)
                        <article class="rounded-xl border border-danger-200 p-4 dark:border-danger-900">
                            <h3 class="font-semibold">{{ $case['case_name'] ?? 'Проверка без названия' }}</h3>
                            <p class="mt-2 text-sm text-danger-700 dark:text-danger-300">{{ $case['failure_explanation'] ?? 'Проверка не пройдена.' }}</p>
                            <dl class="mt-3 space-y-3 text-sm">
                                <div><dt class="font-medium">Ввод</dt><dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ json_encode($case['test_inputs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</dd></div>
                                <div><dt class="font-medium">Ожидаемое поведение</dt><dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ json_encode($case['expected_assertions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</dd></div>
                                <div><dt class="font-medium">Фактический ответ</dt><dd class="mt-1 whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ $case['actual_output'] ?? 'Ответ не получен.' }}</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
