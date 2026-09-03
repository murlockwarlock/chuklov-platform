@if ($preview['mediaUrl'] !== null)
    <div class="mb-3 overflow-hidden rounded-2xl rounded-bl-md bg-white shadow-sm dark:bg-slate-800">
        @if ($preview['mode'] === 'image_caption' && $preview['captionPosition'] === 'above' && $preview['hasText'])
            <div class="px-4 py-3 text-sm leading-6 text-slate-900 dark:text-slate-100">{!! $preview['bodyHtml'] !!}</div>
        @endif
        <img src="{{ $preview['mediaUrl'] }}" alt="" class="max-h-72 w-full object-cover">
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
