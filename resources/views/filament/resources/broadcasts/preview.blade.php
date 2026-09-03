<div class="space-y-4">
    @if (filled($previewError ?? null))
        <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-400/20 dark:bg-danger-400/10 dark:text-danger-300">
            {{ $previewError }}
        </div>
    @endif

    @if ($summary !== null)
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
            <div class="font-medium">Получатели: {{ $summary['eligible'] }} из {{ $summary['matched'] }}</div>
            @if ($summary['reasons'] !== [])
                <div class="mt-1">Исключено: {{ collect($summary['reasons'])->map(fn (int $count, string $reason): string => \App\Filament\Support\BroadcastFailurePresentation::label($reason).': '.$count)->implode('; ') }}</div>
            @endif
        </div>
    @endif

    @if ($preview !== null)
        <div class="mx-auto max-w-md rounded-3xl bg-slate-100 p-4 dark:bg-slate-900">
        <div class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-500">Telegram</div>
        @if ($preview['mode'] === 'image_then_text' && $preview['hasImage'])
            @include('filament.resources.broadcasts._telegram-image', ['preview' => $preview])
        @endif

        @if (($preview['mode'] === 'text' || $preview['mode'] === 'text_then_image') && $preview['hasText'])
            @include('filament.resources.broadcasts._telegram-bubble', ['preview' => $preview])
        @endif

        @if (($preview['mode'] === 'image' || $preview['mode'] === 'text_then_image' || $preview['mode'] === 'image_caption') && $preview['hasImage'])
            @include('filament.resources.broadcasts._telegram-image', ['preview' => $preview])
        @endif

        @if ($preview['mode'] === 'image_then_text' && $preview['hasText'])
            @include('filament.resources.broadcasts._telegram-bubble', ['preview' => $preview])
        @endif

        @if (! $preview['hasText'] && ! $preview['hasImage'])
            <div class="rounded-2xl bg-white px-4 py-3 text-sm text-slate-500 shadow-sm dark:bg-slate-800 dark:text-slate-300">Сообщение пока не заполнено.</div>
        @endif
        </div>
    @endif
</div>
