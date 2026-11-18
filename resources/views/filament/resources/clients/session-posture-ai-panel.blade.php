@php
    $page = $getLivewire();
    $panel = $page->postureAiPanelData();
@endphp

<div class="min-w-0 space-y-4" data-testid="session-posture-ai-panel">
    <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900 dark:border-primary-400/20 dark:bg-primary-400/10 dark:text-primary-100">
        {{ __('Наблюдения AI и подтверждённые данные специалиста показываются раздельно. Анализ не публикуется клиенту автоматически.') }}
    </div>

    @if ($panel['test_two'])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Связанный визуальный тест №2: :name', ['name' => $panel['test_two']['name'] ?? __('тест')]) }}</div>
            <div class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                <div><span class="font-medium">{{ __('До сеанса') }}:</span> {{ $panel['test_two']['before'] ?? __('Не заполнено') }}</div>
                <div><span class="font-medium">{{ __('После сеанса') }}:</span> {{ $panel['test_two']['after'] ?? __('Не заполнено') }}</div>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Это сохранённый результат теста специалиста. AI его не изменяет.') }}</p>
        </div>
    @endif

    @foreach ([$panel['before'], $panel['after']] as $phase)
        <section class="min-w-0 rounded-lg border border-gray-200 p-3 dark:border-white/10" data-testid="session-posture-ai-phase-{{ $phase['label'] }}">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Результат AI: :phase', ['phase' => $phase['label']]) }}</h3>
            <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $phase['status'] }}</p>

            @if ($phase['photos'] !== [])
                <div class="mt-3 grid min-w-0 grid-cols-3 gap-2">
                    @foreach ($phase['photos'] as $photo)
                        <article class="min-w-0">
                            @if ($photo['preview_url'])
                                <a href="{{ $photo['preview_url'] }}" target="_blank" rel="noreferrer" class="block overflow-hidden rounded-lg border border-gray-200 dark:border-white/10" aria-label="{{ __('Открыть фото :filename', ['filename' => $photo['filename']]) }}">
                                    <img src="{{ $photo['preview_url'] }}" alt="{{ $photo['filename'] }}" loading="lazy" class="aspect-square w-full object-cover">
                                </a>
                            @else
                                <div class="flex aspect-square items-center justify-center rounded-lg border border-gray-200 p-2 text-center text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">{{ __('Предпросмотр недоступен') }}</div>
                            @endif
                            <p class="mt-1 truncate text-xs font-medium text-gray-700 dark:text-gray-200" title="{{ $photo['filename'] }}">{{ $photo['filename'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ match ($photo['role']) { 'front' => __('Спереди'), 'side' => __('Сбоку'), 'back' => __('Сзади'), default => __('Фото') } }}</p>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($phase['result'])
                <div class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-400/20 dark:bg-warning-400/10">
                    <div class="text-xs font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-200">{{ __('Наблюдения AI') }}</div>
                    <div class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $phase['result'] }}</div>
                </div>
            @endif

            @if (filled($phase['specialist_result']))
                <div class="mt-3 rounded-lg border border-success-200 bg-success-50 p-3 dark:border-success-400/20 dark:bg-success-400/10">
                    <div class="text-xs font-semibold uppercase tracking-wide text-success-800 dark:text-success-200">{{ __('Версия специалиста') }}</div>
                    <div class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $phase['specialist_result'] }}</div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Сохранено как решение специалиста; подтверждённые поля сеанса не изменены автоматически.') }}</p>
                </div>
            @endif

            @if ($phase['history'] !== [])
                <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('История версий и решений') }}</div>
                    <div class="mt-2 space-y-2">
                        @foreach ($phase['history'] as $row)
                            <div class="rounded-md bg-gray-50 p-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                <div>{{ $row['date'] }} · {{ $row['status'] }} @if ($row['review']) · {{ $row['review'] }} @endif</div>
                                <div class="mt-1">{{ __('AI: :version', ['version' => $row['ai_version']]) }}</div>
                                <div>{{ __('Специалист: :version', ['version' => $row['specialist_version']]) }}</div>
                                @if ($row['author'])
                                    <div>{{ __('Специалист: :author · :date', ['author' => $row['author'], 'date' => $row['reviewed_at'] ?? '—']) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endforeach

    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Подтверждённые данные специалиста') }}</div>
        <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $panel['confirmed_text'] ?: __('Поле «Визуальные изменения в осанке после сеанса» пока не заполнено.') }}</p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Принятие AI-наблюдений не перезаписывает это поле.') }}</p>
    </div>
</div>
