<div class="mb-3 rounded-2xl rounded-bl-md bg-white px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm dark:bg-slate-800 dark:text-slate-100">
    {!! $preview['bodyHtml'] !!}
    @if (is_array($preview['actionButton'] ?? null))
        <a href="{{ $preview['actionButton']['url'] }}" class="mt-3 block rounded-lg bg-sky-500 px-3 py-2 text-center text-sm font-medium text-white" target="_blank" rel="noopener noreferrer">
            {{ $preview['actionButton']['text'] }}
        </a>
    @endif
</div>
