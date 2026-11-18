@php
    $series = is_array($series ?? null) ? $series : [];
    $sessions = is_array($sessions ?? null) ? $sessions : [];
    $sessionCount = count($sessions);
    $xForIndex = static fn (int $index): float => $sessionCount <= 1
        ? 320.0
        : 28.0 + ($index * (584.0 / ($sessionCount - 1)));
@endphp

<article class="min-w-0 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-white/5 sm:p-4">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h4 class="break-words text-sm font-semibold text-gray-950 dark:text-white">{{ $series['label'] }}</h4>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $series['unit'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-300" aria-label="{{ __('Обозначения графика') }}">
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full bg-primary-600" aria-hidden="true"></span>
                {{ __('До') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full bg-warning-500" aria-hidden="true"></span>
                {{ __('После') }}
            </span>
        </div>
    </div>

    <div class="mt-3 min-w-0 overflow-hidden rounded-lg border border-gray-100 bg-gray-50 p-2 dark:border-white/10 dark:bg-gray-950/20">
        <svg
            viewBox="0 0 640 220"
            class="block h-auto w-full"
            role="img"
            aria-label="{{ __('График :label', ['label' => $series['label']]) }}"
        >
            <line x1="28" y1="16" x2="612" y2="16" stroke="currentColor" stroke-opacity=".12" />
            <line x1="28" y1="88" x2="612" y2="88" stroke="currentColor" stroke-opacity=".12" />
            <line x1="28" y1="160" x2="612" y2="160" stroke="currentColor" stroke-opacity=".12" />
            <text x="24" y="13" text-anchor="end" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ $series['max_label'] }}</text>
            <text x="24" y="164" text-anchor="end" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ $series['min_label'] }}</text>

            @foreach ($series['segments']['before'] as $segment)
                @if (count($segment) > 1)
                    <polyline
                        points="@foreach ($segment as $point){{ $point['x'] }},{{ $point['y'] }} @endforeach"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="text-primary-600"
                    />
                @endif
            @endforeach

            @foreach ($series['segments']['after'] as $segment)
                @if (count($segment) > 1)
                    <polyline
                        points="@foreach ($segment as $point){{ $point['x'] }},{{ $point['y'] }} @endforeach"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-dasharray="6 4"
                        class="text-warning-500"
                    />
                @endif
            @endforeach

            @foreach (['before' => 'text-primary-600', 'after' => 'text-warning-500'] as $phase => $pointClass)
                @foreach ($series['points'][$phase] as $point)
                    @if (isset($point['x'], $point['y']))
                        <circle
                            cx="{{ $point['x'] }}"
                            cy="{{ $point['y'] }}"
                            r="4"
                            tabindex="0"
                            class="{{ $pointClass }} fill-current stroke-white stroke-2 outline-none focus:stroke-gray-950 dark:stroke-gray-950 dark:focus:stroke-white"
                            aria-label="{{ __(':phase, :date, значение :value :unit', ['phase' => $phase === 'before' ? __('До') : __('После'), 'date' => $point['full_date'], 'value' => $point['value_label'], 'unit' => $series['unit']]) }}"
                        >
                            <title>{{ $phase === 'before' ? __('До') : __('После') }} · {{ $point['full_date'] }} · {{ $point['value_label'] }} {{ $series['unit'] }}</title>
                        </circle>
                    @endif
                @endforeach
            @endforeach

            @foreach ($sessions as $index => $session)
                @php($x = $xForIndex($index))
                <line x1="{{ $x }}" y1="160" x2="{{ $x }}" y2="166" stroke="currentColor" stroke-opacity=".35" />
                <text x="{{ $x }}" y="184" text-anchor="middle" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ $session['date'] }}</text>
                <title>{{ $session['full_date'] }} · {{ $session['period'] }}</title>
            @endforeach
        </svg>
    </div>

    <div class="mt-2 flex min-w-0 flex-wrap items-start justify-between gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <span>{{ __('Даты сеансов указаны в часовом поясе организации.') }}</span>
        <span>{{ __('Пропуск означает, что значение не сохранено.') }}</span>
    </div>
</article>
