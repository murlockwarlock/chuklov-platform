<div
    x-data="{ selectedFile: null }"
    x-on:messages-attachment-selected.window="selectedFile = $event.detail.name"
    x-on:messages-attachment-cleared.window="selectedFile = null"
    x-show="selectedFile"
    x-cloak
    class="messages-attachment-summary"
    aria-live="polite"
>
    <span class="min-w-0 truncate" x-text="selectedFile"></span>
    <button
        type="button"
        x-on:click="$dispatch('messages-remove-attachment')"
        aria-label="Удалить вложение"
        title="Удалить вложение"
    >
        <x-filament::icon icon="heroicon-o-x-mark" class="block size-4 shrink-0" />
    </button>
</div>
