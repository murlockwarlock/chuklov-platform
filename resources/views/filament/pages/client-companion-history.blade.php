<x-filament-panels::page>
    <div class="space-y-6">
        @if (session('companion_status'))
            <div class="rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-800 dark:border-success-800 dark:bg-success-950/30 dark:text-success-200">
                {{ session('companion_status') }}
            </div>
        @endif

        <x-filament::section
            :heading="$client->full_name ?: 'Клиент'"
            :description="$companion['stateLabel'].($companion['openEscalation'] ? ' · '.$companion['openEscalation']['reasonLabel'] : '')"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-primary-50 px-3 py-1 text-sm font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-200">
                    {{ $companion['stateLabel'] }}
                </span>
                @if ($companion['pending'])
                    <span class="text-sm text-gray-500 dark:text-gray-400">AI обрабатывает сообщение</span>
                @endif
            </div>

            @if ($canManage)
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @if ($companion['state'] === 'human_handoff' && $companion['openEscalation'])
                        <form method="post" action="{{ $urls['resolveAndResume'] }}">
                            @csrf
                            <x-filament::button type="submit" color="primary" size="sm">Закрыть обращение и вернуть AI</x-filament::button>
                        </form>
                        <form method="post" action="{{ $urls['resolve'] }}">
                            @csrf
                            <x-filament::button type="submit" color="gray" outlined size="sm">Закрыть, но оставить AI выключенным</x-filament::button>
                        </form>
                    @elseif ($companion['state'] === 'human_handoff')
                        <form method="post" action="{{ $urls['resume'] }}">
                            @csrf
                            <x-filament::button type="submit" color="success" size="sm">Возобновить AI-помощника</x-filament::button>
                        </form>
                    @endif
                    <form method="post" action="{{ $urls['reset'] }}" onsubmit="return confirm('История останется сохранённой, но AI не будет использовать предыдущие сообщения как память нового контекста. Продолжить?')">
                        @csrf
                        <x-filament::button type="submit" color="warning" outlined size="sm">Очистить контекст AI</x-filament::button>
                    </form>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">История не удаляется. AI начнёт следующий контекст без прежних сообщений.</p>
            @endif

            <div class="mt-5 flex flex-wrap gap-2">
                @if ($canExport)
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=txt&identity=identified">TXT</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=json&identity=identified">JSON</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=txt&identity=pseudonymized">Без прямых идентификаторов</x-filament::button>
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['export'] }}?format=json&identity=pseudonymized">JSON без прямых идентификаторов</x-filament::button>
                @endif
                @if ($canExportMetadata)
                    <x-filament::button tag="a" size="sm" color="gray" outlined href="{{ $urls['metadataExport'] }}">Расширенные технические метаданные</x-filament::button>
                @endif
            </div>
        </x-filament::section>

        @if ($canManage)
            <x-filament::section heading="Написать сообщение" description="Сообщение уйдёт в тот же канал, что и последнее сообщение клиента. Специалист может написать вручную, даже когда AI отвечает.">
                <form method="post" action="{{ $urls['reply'] }}">
                    @csrf
                    <textarea id="companion-staff-reply" name="body" class="fi-input min-h-28 w-full max-w-3xl" maxlength="10000" required></textarea>
                    <x-filament::button class="mt-3" type="submit" color="primary">Отправить сообщение</x-filament::button>
                </form>
            </x-filament::section>
        @endif

        <x-filament::section heading="Диалог" description="Сначала показаны последние сообщения. Более ранняя история загружается отдельно.">
            <div class="space-y-3">
                @forelse ($companion['messages'] as $message)
                    @php
                        $isClient = $message['role'] === 'client';
                        $isSystem = $message['role'] === 'system';
                        $bubbleClass = $isClient
                            ? 'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white'
                            : ($message['role'] === 'staff'
                                ? 'bg-success-50 text-success-950 dark:bg-success-950/30 dark:text-success-100'
                                : 'bg-primary-50 text-primary-950 dark:bg-primary-950/30 dark:text-primary-100');
                    @endphp
                    <div class="flex {{ $isSystem ? 'justify-center' : ($isClient ? 'justify-start' : 'justify-end') }}">
                        <article class="min-w-0 max-w-3xl rounded-2xl px-4 py-3 {{ $isSystem ? 'border border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300' : $bubbleClass }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold">
                                <span>{{ $message['roleLabel'] }}</span>
                                @if ($message['transportLabel'])
                                    <span class="font-normal opacity-70">{{ $message['transportLabel'] }}</span>
                                @endif
                                <time class="font-normal opacity-60">{{ $message['occurredAt'] }}</time>
                                @if ($message['feedback'])
                                    <span class="font-normal opacity-70">Оценка: {{ $message['feedback'] === 'helpful' ? 'полезно' : 'не помогло' }}</span>
                                @endif
                            </div>
                            <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ $message['content'] }}</p>
                            @if ($message['attachmentCount'] > 0)
                                <p class="mt-2 text-xs opacity-70">{{ $message['attachmentCount'] === 1 ? 'Изображение' : $message['attachmentCount'].' изображений' }}</p>
                            @endif
                            @if ($message['deliveryNotice'])
                                <div class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                                    <p class="font-semibold">{{ $message['deliveryNotice']['title'] }}</p>
                                    <p class="mt-1 leading-5">{{ $message['deliveryNotice']['body'] }}</p>
                                </div>
                            @endif
                            @if ($message['traceUrl'])
                                <x-filament::button tag="a" size="sm" color="gray" outlined class="mt-3" href="{{ $message['traceUrl'] }}">Открыть технические данные AI</x-filament::button>
                            @endif
                        </article>
                    </div>
                @empty
                    <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">История общения пока пуста.</div>
                @endforelse
            </div>
            @if ($companion['hasOlder'])
                <x-filament::button tag="a" size="sm" color="gray" outlined class="mt-5" href="{{ $urls['history'] }}?before={{ $companion['nextBeforeMessageId'] }}">Загрузить более ранние сообщения</x-filament::button>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
