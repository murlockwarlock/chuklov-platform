<button
    type="button"
    class="messages-attachment-trigger"
    x-on:click="$dispatch('messages-open-attachment')"
    aria-label="Добавить вложение"
    title="Добавить вложение"
>
    <x-filament::icon icon="heroicon-o-paper-clip" class="block size-5 shrink-0" />
</button>
