<div class="space-y-4">
    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $filename }}</p>
    <audio class="w-full" controls preload="metadata" src="{{ $url }}">
        {{ __('Ваш браузер не поддерживает воспроизведение аудио.') }}
    </audio>
</div>
