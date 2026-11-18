@php
    $page = $getLivewire();
    $panel = $page->clinicalDocumentAiPanelData();
@endphp

<div class="min-w-0 space-y-4" data-testid="session-document-ai-panel">
    <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900 dark:border-primary-400/20 dark:bg-primary-400/10 dark:text-primary-100">
        {{ __('AI показывает извлечённые сведения отдельно от медицинских полей сеанса. Проверенный результат не публикуется клиенту автоматически.') }}
    </div>

    @if ($panel['sources'])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Источник') }}</div>
            <div class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $panel['sources'] }}</div>
        </div>
    @endif

    @if ($panel['result'])
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-400/20 dark:bg-warning-400/10">
            <div class="text-xs font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-200">{{ __('Оригинальный результат AI') }}</div>
            <div class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $panel['result'] }}</div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Оригинал сохранён отдельно и не заменяется правкой специалиста.') }}</p>
        </div>
    @endif

    @if (filled($panel['specialist_result']))
        <div class="rounded-lg border border-success-200 bg-success-50 p-3 dark:border-success-400/20 dark:bg-success-400/10">
            <div class="text-xs font-semibold uppercase tracking-wide text-success-800 dark:text-success-200">{{ __('Проверенная версия специалиста') }}</div>
            <div class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $panel['specialist_result'] }}</div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Сохранено как решение специалиста; медицинские поля сеанса не изменены.') }}</p>
        </div>
    @endif

    @if ($panel['review'])
        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
            <span class="font-medium">{{ __('Решение специалиста') }}:</span> {{ $panel['review'] }}
        </div>
    @endif

    @if ($panel['history'] !== [])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('История AI-разбора и решений') }}</div>
            <div class="mt-2 space-y-2">
                @foreach ($panel['history'] as $row)
                    <div class="rounded-md bg-gray-50 p-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <div>{{ $row['date'] }} · {{ $row['document'] }} · {{ $row['status'] }} @if ($row['review']) · {{ $row['review'] }} @endif</div>
                        <div class="mt-1">{{ __('AI: :version', ['version' => $row['ai_version']]) }}</div>
                        <div>{{ __('Специалист: :version', ['version' => $row['specialist_version']]) }}</div>
                        @if ($row['author'])
                            <div>{{ __('Решение специалиста: :author · :date', ['author' => $row['author'], 'date' => $row['reviewed_at'] ?? '—']) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
