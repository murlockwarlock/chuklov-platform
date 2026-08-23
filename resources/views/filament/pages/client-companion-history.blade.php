<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Клиент</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-100">{{ $client->full_name ?: 'Клиент' }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                    {{ $companion['stateLabel'] }}
                    @if($companion['openEscalation'])
                        · {{ $companion['openEscalation']['reasonLabel'] }}
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($canExport)
                    <a class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" href="{{ $urls['export'] }}?format=txt&identity=identified">TXT</a>
                    <a class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" href="{{ $urls['export'] }}?format=json&identity=identified">JSON</a>
                    <a class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" href="{{ $urls['export'] }}?format=txt&identity=pseudonymized">Без прямых идентификаторов</a>
                    <a class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" href="{{ $urls['export'] }}?format=json&identity=pseudonymized">JSON без прямых идентификаторов</a>
                @endif
                @if($canExportMetadata)
                    <a class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" href="{{ $urls['metadataExport'] }}">Метаданные</a>
                @endif
            </div>
        </div>

        @if($canManage)
            <div class="flex flex-wrap gap-2">
                @if($companion['state'] === 'human_handoff')
                    @if($companion['openEscalation'])
                        <form method="post" action="{{ $urls['resolve'] }}">@csrf<button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Закрыть обращение</button></form>
                    @else
                        <form method="post" action="{{ $urls['resume'] }}">@csrf<button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white" type="submit">Возобновить AI-помощника</button></form>
                    @endif
                @endif
                <form method="post" action="{{ $urls['reset'] }}" onsubmit="return confirm('Предыдущая история останется сохранённой, но новый диалог не будет использовать её как обычную память. Продолжить?')">@csrf<button class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-800" type="submit">Начать новый диалог</button></form>
            </div>
        @endif

        <div class="space-y-4">
            @forelse($companion['messages'] as $message)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                        <span>{{ $message['roleLabel'] }}</span>
                        @if($message['transportLabel'])<span>· {{ $message['transportLabel'] }}</span>@endif
                        <span>· {{ $message['occurredAt'] }}</span>
                        @if($message['feedback'])<span>· Оценка: {{ $message['feedback'] === 'helpful' ? 'полезно' : 'не помогло' }}</span>@endif
                    </div>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-slate-800 dark:text-slate-200">{{ $message['content'] }}</p>
                    @if($message['attachmentCount'] > 0)
                        <p class="mt-2 text-sm text-slate-500">{{ $message['attachmentCount'] === 1 ? 'Изображение' : $message['attachmentCount'].' изображений' }}</p>
                    @endif
                    @if($message['traceUrl'])
                        <a class="mt-3 inline-block text-xs font-medium text-indigo-600 hover:underline" href="{{ $message['traceUrl'] }}">Открыть защищённый AI-трейс</a>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">История общения пока пуста.</div>
            @endforelse
            @if($companion['hasOlder'])
                <a class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700" href="{{ $urls['history'] }}?before={{ $companion['nextBeforeMessageId'] }}">Загрузить более ранние сообщения</a>
            @endif
        </div>

        @if($canManage && $companion['state'] === 'human_handoff')
            <form method="post" action="{{ $urls['reply'] }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                @csrf
                <label class="block text-sm font-semibold text-slate-800 dark:text-slate-200" for="companion-staff-reply">Ответ специалиста</label>
                <textarea id="companion-staff-reply" name="body" class="mt-3 min-h-28 w-full rounded-lg border border-slate-300 p-3 text-sm" maxlength="10000" required></textarea>
                <button class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Отправить в тот же разговор</button>
            </form>
        @endif
    </div>
</x-filament-panels::page>
