<button
    type="button"
    class="messages-attachment-trigger"
    data-testid="messages-attachment-trigger"
    x-on:click="$el.closest('form')?.querySelector('.filepond--browser, input[type=file]')?.click()"
    aria-label="{{ __('Добавить вложение') }}"
    title="{{ __('Добавить вложение') }}"
>
    <x-filament::icon icon="heroicon-o-paper-clip" class="block size-5 shrink-0" />
</button>
