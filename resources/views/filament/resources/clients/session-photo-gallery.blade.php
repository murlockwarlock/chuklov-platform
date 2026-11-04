<div class="min-w-0">
    @if ($attachments === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Фото пока не привязаны.') }}</p>
    @else
        <div class="grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
            @foreach ($attachments as $attachment)
                <article class="min-w-0 space-y-2">
                    @if ($attachment['preview_url'])
                        <a
                            href="{{ $attachment['preview_url'] }}"
                            target="_blank"
                            rel="noreferrer"
                            class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5"
                            aria-label="{{ __('Открыть фото :filename', ['filename' => $attachment['filename']]) }}"
                        >
                            <img
                                src="{{ $attachment['preview_url'] }}"
                                alt="{{ $attachment['filename'] }}"
                                loading="lazy"
                                class="aspect-square w-full object-cover"
                            >
                        </a>
                    @else
                        <div class="flex aspect-square items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-3 text-center text-xs text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                            {{ __('Предпросмотр недоступен') }}
                        </div>
                    @endif
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white" title="{{ $attachment['filename'] }}">{{ $attachment['filename'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $attachment['uploaded_at'] }}</p>
                        @if ($attachment['download_url'])
                            <a href="{{ $attachment['download_url'] }}" class="text-xs text-primary-600 hover:underline dark:text-primary-400">{{ __('Скачать') }}</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Чтобы убрать фото из галереи, используйте действие «Отвязать файл». Исходный файл клиента не удаляется.') }}</p>
    @endif
</div>
