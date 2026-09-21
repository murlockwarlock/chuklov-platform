<div class="min-w-0">
    @if ($url === null)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Предпросмотр для этого типа файла недоступен.') }}</p>
    @elseif ($mimeType === 'application/pdf')
        <iframe
            src="{{ $url }}"
            title="{{ $filename }}"
            class="h-[70vh] min-h-96 w-full rounded-lg border border-gray-200 dark:border-white/10"
        ></iframe>
    @elseif (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true))
        <div class="flex min-h-96 items-center justify-center rounded-lg bg-gray-50 p-3 dark:bg-white/5">
            <img
                src="{{ $url }}"
                alt="{{ $filename }}"
                class="max-h-[70vh] max-w-full object-contain"
            >
        </div>
    @elseif ($mimeType === 'text/plain')
        <iframe
            src="{{ $url }}"
            title="{{ $filename }}"
            sandbox
            class="h-[70vh] min-h-96 w-full rounded-lg border border-gray-200 dark:border-white/10"
        ></iframe>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Предпросмотр для этого типа файла недоступен.') }}</p>
    @endif
</div>
