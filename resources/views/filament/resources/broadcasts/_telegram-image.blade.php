@php
    $mediaItems = $preview['mediaItems'] ?? ($preview['mediaUrl'] !== null ? [['type' => 'photo', 'url' => $preview['mediaUrl'], 'name' => null]] : []);
@endphp

@if ($mediaItems !== [])
    <div class="mb-3 overflow-hidden rounded-2xl rounded-bl-md bg-white shadow-sm dark:bg-slate-800">
        @if ($preview['mode'] === 'image_caption' && $preview['captionPosition'] === 'above' && $preview['hasText'])
            <div class="px-4 py-3 text-sm leading-6 text-slate-900 dark:text-slate-100">{!! $preview['bodyHtml'] !!}</div>
        @endif

        <div class="space-y-2 p-2">
            @foreach ($mediaItems as $item)
                @if ($item['type'] === 'video')
                    <video controls preload="metadata" class="max-h-72 w-full rounded-xl bg-slate-950 object-contain">
                        <source src="{{ $item['url'] }}" type="video/mp4">
                    </video>
                @elseif ($item['type'] === 'document')
                    <div class="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-4 dark:border-white/10">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-200">Файл</div>
                        <div class="min-w-0 text-sm text-slate-900 dark:text-slate-100">
                            <div class="truncate font-medium">{{ $item['name'] ?: 'Документ' }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">Будет отправлен как файл</div>
                        </div>
                    </div>
                @else
                    <img src="{{ $item['url'] }}" alt="{{ $item['name'] ?: '' }}" class="max-h-72 w-full rounded-xl object-cover">
                @endif
            @endforeach
        </div>

        @if ($preview['mode'] === 'image_caption' && $preview['captionPosition'] === 'below' && $preview['hasText'])
            <div class="px-4 py-3 text-sm leading-6 text-slate-900 dark:text-slate-100">{!! $preview['bodyHtml'] !!}</div>
        @endif
        @if (is_array($preview['actionButton'] ?? null))
            <a href="{{ $preview['actionButton']['url'] }}" class="block border-t border-slate-200 px-4 py-3 text-center text-sm font-medium text-sky-600 dark:border-white/10 dark:text-sky-400" target="_blank" rel="noopener noreferrer">
                {{ $preview['actionButton']['text'] }}
            </a>
        @endif
    </div>
@endif
