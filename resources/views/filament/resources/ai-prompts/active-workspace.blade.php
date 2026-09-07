@php
    $page = $getLivewire();
    $prompt = $page->prompt();
    $activeVersion = $page->activeVersion();
    $systemPrompt = $activeVersion?->system_prompt ?? '';
@endphp

<div
    class="min-w-0 space-y-4"
    x-data="{ activeTab: 'full', expanded: false }"
    data-testid="active-prompt-workspace"
>
    <div class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $page->capabilityLabel($prompt) }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2 class="min-w-0 text-xl font-semibold text-gray-950 dark:text-white">{{ $prompt->name }}</h2>
                @if ($activeVersion)
                    <x-filament::badge color="success">Активная версия: v{{ $activeVersion->version }}</x-filament::badge>
                @else
                    <x-filament::badge color="warning">Нет активной версии</x-filament::badge>
                @endif
            </div>
            <p class="mt-1 text-sm font-medium text-success-700 dark:text-success-400">Этот промпт сейчас используется AI</p>
        </div>

        <div class="flex max-w-full flex-wrap gap-2" data-testid="prompt-primary-actions">
            {{ $page->editPromptAction }}
            {{ $page->playgroundAction }}
            {{ $page->evaluationsAction }}
            {{ $page->exportAction }}
        </div>
    </div>

    @if ($activeVersion)
        <div class="min-w-0 border-b border-gray-200 dark:border-white/10">
            <div class="flex max-w-full gap-1 overflow-x-auto" role="tablist" aria-label="Разделы активного промпта">
                @foreach ([
                    'full' => 'Полный промпт',
                    'safety' => 'Safety guardrails',
                    'runtime' => 'Runtime-контракт',
                ] as $key => $label)
                    <button
                        type="button"
                        class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium"
                        :class="activeTab === '{{ $key }}' ? 'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' : 'border-transparent text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white'"
                        x-on:click="activeTab = '{{ $key }}'; expanded = false"
                        :aria-selected="activeTab === '{{ $key }}'"
                        role="tab"
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @foreach ([
            'full' => $systemPrompt,
            'safety' => \App\Filament\Support\AiPromptTextSections::guardrails($systemPrompt),
            'runtime' => \App\Filament\Support\AiPromptTextSections::runtimeContract($systemPrompt),
        ] as $key => $text)
            <div x-show="activeTab === '{{ $key }}'" role="tabpanel" class="min-w-0">
                <div
                    class="relative min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
                    :class="expanded ? '' : 'max-h-64'"
                >
                    <pre class="max-w-full whitespace-pre-wrap break-words p-4 font-sans text-sm leading-6 text-gray-800 dark:text-gray-200">{{ $text }}</pre>
                    <div x-show="! expanded" class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-white dark:from-gray-900"></div>
                </div>
                <button
                    type="button"
                    class="mt-2 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                    x-on:click="expanded = ! expanded"
                    x-text="expanded ? 'Свернуть' : 'Показать полностью'"
                ></button>
            </div>
        @endforeach
    @else
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
            Создайте и активируйте версию, чтобы AI получил рабочую инструкцию.
        </div>
    @endif

    @if ($page->hasDraft())
        <div class="flex flex-wrap gap-2">
            {{ $page->activateDraftAction }}
        </div>
    @endif
</div>
