<x-filament-panels::page>
    <div @if ($this->shouldPollWorkspace()) wire:poll.5s.visible="refreshWorkspace" @endif>
        {{ $this->content }}
    </div>
</x-filament-panels::page>
