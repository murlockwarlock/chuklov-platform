<x-filament-panels::page full-height>
    <div wire:poll.visible.10s="refreshWorkspace" class="relative flex h-full min-h-0 min-w-0">
        <div class="grid h-full min-h-0 min-w-0 flex-1 grid-cols-1 gap-3 md:grid-cols-[18rem_minmax(0,1fr)] xl:grid-cols-[19rem_minmax(0,1fr)_22rem]">
            <aside class="{{ $mobileChatOpen ? 'hidden md:flex' : 'flex' }} h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="shrink-0 border-b border-gray-200 px-4 py-4 dark:border-white/10">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="min-w-0 truncate text-lg font-semibold text-gray-950 dark:text-white">Сообщения</h2>
                    </div>
                    <label class="sr-only" for="messages-search">Поиск клиентов и диалогов</label>
                    <div class="relative mt-3">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input
                            id="messages-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Поиск клиентов и диалогов"
                            class="block w-full min-w-0 rounded-xl border-gray-300 py-2.5 pl-9 pr-3 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                        >
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    @forelse ($dialogs as $dialog)
                        <button
                            type="button"
                            wire:key="dialog-{{ $dialog['clientId'] }}"
                            wire:click="selectClient({{ $dialog['clientId'] }})"
                            class="flex w-full min-w-0 items-start gap-3 border-b border-gray-100 px-4 py-3 text-left transition hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5 {{ $dialog['selected'] ? 'bg-primary-50/70 dark:bg-primary-950/30' : '' }}"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                {{ $dialog['initials'] }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="min-w-0 truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $dialog['name'] }}</span>
                                    @if ($dialog['lastActivityLabel'])
                                        <time class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400">{{ $dialog['lastActivityLabel'] }}</time>
                                    @endif
                                </span>
                                <span class="mt-1 block min-w-0 truncate text-xs text-gray-600 dark:text-gray-300">{{ $dialog['preview'] ?: 'История пока пуста' }}</span>
                                <span class="mt-2 flex min-w-0 flex-wrap items-center gap-1.5">
                                    @if ($dialog['channelLabel'])
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $dialog['channelLabel'] }}</span>
                                    @endif
                                    <span class="max-w-full truncate text-[11px] text-gray-500 dark:text-gray-400">{{ $dialog['stateLabel'] }}</span>
                                </span>
                            </span>
                        </button>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ trim($search) !== '' ? 'Клиенты не найдены' : 'Диалогов пока нет' }}
                        </div>
                    @endforelse
                </div>
            </aside>

            <main class="{{ $mobileChatOpen ? 'flex' : 'hidden md:flex' }} h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                @if ($selectedClient && $selectedDialog)
                    <header class="flex min-w-0 shrink-0 items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10 sm:px-5">
                        <button type="button" wire:click="backToDialogs" class="shrink-0 rounded-lg p-2 text-gray-500 hover:bg-gray-100 md:hidden dark:hover:bg-white/10" aria-label="К списку диалогов">
                            <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
                        </button>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-800 dark:bg-primary-950/50 dark:text-primary-200">{{ $selectedDialog['initials'] }}</span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $selectedClient]) }}" class="block min-w-0 truncate text-base font-semibold text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-300">
                                {{ $selectedDialog['name'] }}
                            </a>
                            <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $selectedDialog['channelLabel'] ?: 'Канал не подключён' }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $selectedDialog['stateLabel'] }}</span>
                                @if (($historyState['pending'] ?? false))
                                    <span class="text-primary-600 dark:text-primary-300">AI обрабатывает сообщение</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            @if ($canManage && ($historyState['state'] ?? null) === 'human_handoff')
                                @if ($historyState['openEscalation'] ?? false)
                                    <x-filament::button type="button" wire:click="resolveAndResume" size="sm" color="primary">Вернуть AI</x-filament::button>
                                    <x-filament::button type="button" wire:click="resolve" size="sm" color="gray" outlined class="hidden sm:inline-flex">Закрыть обращение</x-filament::button>
                                @else
                                    <x-filament::button type="button" wire:click="resumeAi" size="sm" color="primary">Вернуть AI</x-filament::button>
                                @endif
                            @endif
                            <button type="button" wire:click="openClientInfo" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 xl:hidden dark:hover:bg-white/10" aria-label="Информация о клиенте">
                                <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" />
                            </button>
                        </div>
                    </header>

                    <section class="min-h-0 flex-1 overflow-y-auto bg-gray-50/60 px-3 py-4 dark:bg-gray-950/30 sm:px-5">
                        @if (($historyState['hasOlder'] ?? false))
                            <div class="mb-4 text-center">
                                <x-filament::button type="button" wire:click="loadOlderMessages" size="sm" color="gray" outlined>Загрузить предыдущие</x-filament::button>
                            </div>
                        @endif

                        <div class="space-y-4">
                            @forelse ($history as $message)
                                @if ($message['showDate'] ?? false)
                                    <div class="flex items-center gap-3 py-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">
                                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                                        <span>{{ $message['dateLabel'] }}</span>
                                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                                    </div>
                                @endif
                                @php
                                    $isClientMessage = $message['role'] === 'client';
                                    $isSystemMessage = $message['role'] === 'system';
                                    $isAiMessage = $message['role'] === 'ai';
                                    $bubbleClass = $isClientMessage
                                        ? 'bg-white text-gray-950 ring-1 ring-gray-200 dark:bg-white/10 dark:text-white dark:ring-white/10'
                                        : ($isAiMessage
                                            ? 'bg-amber-50 text-amber-950 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-100 dark:ring-amber-800'
                                            : 'bg-primary-50 text-primary-950 ring-1 ring-primary-200 dark:bg-primary-950/30 dark:text-primary-100 dark:ring-primary-800');
                                @endphp
                                <div wire:key="timeline-{{ $message['id'] }}" class="flex {{ $isSystemMessage ? 'justify-center' : ($isClientMessage ? 'justify-start' : 'justify-end') }}">
                                    <article class="min-w-0 max-w-[min(44rem,90%)] rounded-2xl px-4 py-3 shadow-sm {{ $isSystemMessage ? 'border border-gray-200 bg-white text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300' : $bubbleClass }}">
                                        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold">
                                            <span>{{ $message['roleLabel'] }}</span>
                                            @if ($message['authorName'] ?? false)
                                                <span class="max-w-full truncate font-normal opacity-80">{{ $message['authorName'] }}</span>
                                            @endif
                                            @if ($message['transportLabel'])
                                                <span class="font-normal opacity-70">{{ $message['transportLabel'] }}</span>
                                            @endif
                                            <time class="font-normal opacity-60">{{ $message['timeLabel'] }}</time>
                                        </div>
                                        @if (($message['content'] ?? '') !== '')
                                            <div class="prose prose-sm mt-2 max-w-none break-words leading-6 dark:prose-invert">{!! \App\Filament\Support\RichTextPresentation::html($message['content']) !!}</div>
                                        @endif
                                        @if (($message['attachments'] ?? []) !== [])
                                            <div class="mt-3 flex min-w-0 flex-wrap gap-2">
                                                @foreach ($message['attachments'] as $attachment)
                                                    <span class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-lg bg-black/5 px-2.5 py-1.5 text-xs dark:bg-white/10">
                                                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-3.5 w-3.5 shrink-0" />
                                                        <span class="min-w-0 truncate">{{ $attachment['name'] ?: $attachment['type'] }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @elseif (($message['attachmentCount'] ?? 0) > 0)
                                            <p class="mt-2 text-xs opacity-70">Вложение</p>
                                        @endif
                                        @if ($message['deliveryNotice'] ?? false)
                                            <div class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-800 dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                                                <p class="font-semibold">{{ $message['deliveryNotice']['title'] }}</p>
                                                <p class="mt-1 leading-5">{{ $message['deliveryNotice']['body'] }}</p>
                                            </div>
                                        @endif
                                        @if ($message['traceUrl'] ?? false)
                                            <a href="{{ $message['traceUrl'] }}" class="mt-3 inline-flex text-xs font-medium text-gray-600 underline underline-offset-2 dark:text-gray-300">Открыть технические данные AI</a>
                                        @endif
                                    </article>
                                </div>
                            @empty
                                <div class="flex min-h-64 items-center justify-center px-5 text-center">
                                    <div>
                                        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600" />
                                        <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $selectedDialog['hasConversation'] ? 'В истории пока нет сообщений' : 'История пока пуста' }}</p>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Начните диалог с выбранным клиентом.</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </section>

                    @if ($canManage)
                        <div class="shrink-0 border-t border-gray-200 bg-white px-3 py-3 dark:border-white/10 dark:bg-gray-900 sm:px-5">
                            {{ $this->composer }}
                        </div>
                    @endif
                @else
                    <div class="flex min-h-[32rem] flex-1 items-center justify-center px-6 text-center">
                        <div>
                            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">Выберите диалог</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Здесь появится общая история клиента, AI и специалиста.</p>
                        </div>
                    </div>
                @endif
            </main>

            @if ($selectedClient && $clientSummary)
                @if ($showClientInfo)
                    <button type="button" wire:click="closeClientInfo" class="fixed inset-0 z-10 bg-gray-950/30 xl:hidden" aria-label="Закрыть информацию о клиенте"></button>
                @endif
                <aside class="{{ $showClientInfo ? 'flex' : 'hidden xl:flex' }} absolute inset-y-0 right-0 z-20 min-w-0 w-full max-w-sm flex-col overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-white/10 dark:bg-gray-900 xl:static xl:z-auto xl:w-auto xl:max-w-none xl:shadow-sm">
                    <div class="flex items-start gap-3 border-b border-gray-200 px-5 py-5 dark:border-white/10">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-800 dark:bg-primary-950/50 dark:text-primary-200">{{ $clientSummary['initials'] }}</span>
                        <div class="min-w-0 flex-1">
                            <h2 class="break-words text-base font-semibold text-gray-950 dark:text-white">{{ $clientSummary['name'] }}</h2>
                            @if ($clientSummary['phone'])
                                <p class="mt-1 break-words text-sm text-gray-600 dark:text-gray-300">{{ $clientSummary['phone'] }}</p>
                            @endif
                            @if ($clientSummary['email'])
                                <p class="break-words text-sm text-gray-600 dark:text-gray-300">{{ $clientSummary['email'] }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="closeClientInfo" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 xl:hidden dark:hover:bg-white/10" aria-label="Закрыть информацию о клиенте">
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="space-y-5 px-5 py-5">
                        @php
                            $languageLabel = match (strtolower((string) $clientSummary['language'])) {
                                'ru' => 'Русский',
                                'en' => 'Английский',
                                default => 'Не указан',
                            };
                        @endphp
                        <div class="flex flex-wrap gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $clientSummary['telegramConnected'] ? 'bg-success-50 text-success-700 dark:bg-success-950/30 dark:text-success-200' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' }}">
                                {{ $clientSummary['telegramConnected'] ? 'Telegram подключён' : 'Telegram не подключён' }}
                            </span>
                            @if ($clientSummary['language'])
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">Язык: {{ $languageLabel }}</span>
                            @endif
                        </div>

                        <div class="flex min-w-0 flex-wrap gap-2">
                            <x-filament::button tag="a" href="{{ $clientSummary['urls']['client'] }}" size="sm" color="primary">Открыть карточку</x-filament::button>
                            <x-filament::button tag="a" href="{{ $clientSummary['urls']['bookings'] }}" size="sm" color="gray" outlined>Записи</x-filament::button>
                            <x-filament::button tag="a" href="{{ $clientSummary['urls']['sessions'] }}" size="sm" color="gray" outlined>Сессии</x-filament::button>
                        </div>

                        <section class="border-t border-gray-100 pt-5 dark:border-white/10">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Ближайшая запись</h3>
                            @if ($clientSummary['upcomingBooking'])
                                <a href="{{ $clientSummary['urls']['upcomingBooking'] }}" class="mt-3 block rounded-xl bg-gray-50 p-3 transition hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10">
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $clientSummary['upcomingBooking']['date'] }} · {{ $clientSummary['upcomingBooking']['time'] }}</p>
                                    <p class="mt-1 break-words text-sm text-gray-700 dark:text-gray-200">{{ $clientSummary['upcomingBooking']['service'] ?: 'Услуга не указана' }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $clientSummary['upcomingBooking']['status'] }}</p>
                                </a>
                            @else
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Нет предстоящих записей</p>
                            @endif
                        </section>

                    </div>
                </aside>
            @endif
        </div>
    </div>
</x-filament-panels::page>
