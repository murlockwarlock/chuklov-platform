@php
    $items = \App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource::mediaPreviewItems($record);
@endphp

<div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
    <div class="text-sm font-medium text-gray-950 dark:text-white">Текущее медиа</div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($items as $item)
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                @if ($item['type'] === 'video')
                    <video controls preload="metadata" class="h-36 w-full bg-gray-950 object-contain">
                        <source src="{{ $item['url'] }}" type="video/mp4">
                    </video>
                @elseif ($item['type'] === 'document')
                    <div class="flex h-36 items-center justify-center bg-gray-50 px-3 text-center dark:bg-white/5">
                        <div>
                            <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Файл</div>
                            <div class="mt-1 break-all text-sm text-gray-950 dark:text-white">{{ $item['name'] ?: 'Документ' }}</div>
                        </div>
                    </div>
                @else
                    <img src="{{ $item['url'] }}" alt="{{ $item['alt'] ?: '' }}" class="h-36 w-full object-cover">
                @endif
                <div class="border-t border-gray-200 px-3 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    {{ match ($item['type']) { 'video' => 'Видео', 'document' => 'Документ', default => 'Фото' } }}
                    @if ($item['name'])
                        · {{ $item['name'] }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
