<button
    type="button"
    class="messages-attachment-trigger"
    x-on:click="$dispatch('messages-open-attachment')"
    aria-label="{{ __('Добавить вложение') }}"
    title="{{ __('Добавить вложение') }}"
>
    <x-filament::icon icon="heroicon-o-paper-clip" class="block size-5 shrink-0" />
</button>
