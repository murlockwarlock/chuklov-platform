<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section :heading="$client->full_name ?: 'Клиент'" :description="$companion['stateLabel'].($companion['openEscalation'] ? ' · '.$companion['openEscalation']['reasonLabel'] : '')">
            <div class="flex flex-wrap gap-2">
                @if($canExport)
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=txt&identity=identified">TXT</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=json&identity=identified">JSON</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=txt&identity=pseudonymized">Без прямых идентификаторов</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=json&identity=pseudonymized">JSON без прямых идентификаторов</x-filament::button>
                @endif
                @if($canExportMetadata)
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['metadataExport'] }}">Расширенные технические метаданные</x-filament::button>
                @endif
            </div>
        </x-filament::section>

        @if($canManage)
            <div class="flex flex-wrap gap-2">
                @if($companion['state'] === 'human_handoff')
                    @if($companion['openEscalation'])
                        <form method="post" action="{{ $urls['resolve'] }}">@csrf<x-filament::button type="submit" color="primary" size="sm">Закрыть обращение</x-filament::button></form>
                    @else
                        <form method="post" action="{{ $urls['resume'] }}">@csrf<x-filament::button type="submit" color="success" size="sm">Возобновить AI-помощника</x-filament::button></form>
                    @endif
                @endif
                <form method="post" action="{{ $urls['reset'] }}" onsubmit="return confirm('Предыдущая история останется сохранённой, но новый диалог не будет использовать её как обычную память. Продолжить?')">@csrf<x-filament::button type="submit" color="warning" outlined size="sm">Начать новый диалог</x-filament::button></form>
            </div>
        @endif

        <x-filament::section heading="История общения">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($companion['messages'] as $message)
                    <article class="py-5 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <span>{{ $message['roleLabel'] }}</span>
                        @if($message['transportLabel'])<span>· {{ $message['transportLabel'] }}</span>@endif
                        <span>· {{ $message['occurredAt'] }}</span>
                        @if($message['feedback'])<span>· Оценка: {{ $message['feedback'] === 'helpful' ? 'полезно' : 'не помогло' }}</span>@endif
                    </div>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-gray-950 dark:text-white">{{ $message['content'] }}</p>
                    @if($message['attachmentCount'] > 0)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message['attachmentCount'] === 1 ? 'Изображение' : $message['attachmentCount'].' изображений' }}</p>
                    @endif
                    @if($message['deliveryNotice'])
                        <div class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                            <p class="font-semibold">{{ $message['deliveryNotice']['title'] }}</p>
                            <p class="mt-1 leading-5">{{ $message['deliveryNotice']['body'] }}</p>
                        </div>
                    @endif
                    @if($message['traceUrl'])
                        <x-filament::button tag="a" size="sm" color="gray" outlined class="mt-3" href="{{ $message['traceUrl'] }}">Открыть защищённый AI-трейс</x-filament::button>
                    @endif
                    </article>
                @empty
                    <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">История общения пока пуста.</div>
                @endforelse
            </div>
            @if($companion['hasOlder'])
                <x-filament::button tag="a" size="sm" color="gray" outlined class="mt-5" href="{{ $urls['history'] }}?before={{ $companion['nextBeforeMessageId'] }}">Загрузить более ранние сообщения</x-filament::button>
            @endif
        </x-filament::section>

        @if($canManage && $companion['state'] === 'human_handoff')
            <x-filament::section heading="Ответ специалиста">
            <form method="post" action="{{ $urls['reply'] }}">
                @csrf
                <textarea id="companion-staff-reply" name="body" class="fi-input mt-3 min-h-28 w-full" maxlength="10000" required></textarea>
                <x-filament::button class="mt-3" type="submit" color="primary">Отправить в тот же разговор</x-filament::button>
            </form>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
