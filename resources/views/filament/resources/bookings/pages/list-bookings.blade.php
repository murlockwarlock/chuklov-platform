<x-filament-panels::page>
    @php
        $days = $this->journalDays;
        $grid = $this->journalGrid;
        $weekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $rowHeight = 44;
        $calendarBottomPadding = 16;
        $calendarHeight = ($grid['rows'] * $rowHeight) + $calendarBottomPadding;
        $minuteStyle = fn (int $minutes): string => 'top: '.(($minutes - $grid['start']) * ($rowHeight / 30)).'px;';
        $heightStyle = fn (int $start, int $end): string => 'top: '.(($start - $grid['start']) * ($rowHeight / 30)).'px; height: '.max($rowHeight, ($end - $start) * ($rowHeight / 30)).'px;';
        $isOpen = fn (array $day, int $minutes): bool => collect($day['intervals'])->contains(fn (array $interval): bool => $minutes >= $interval['start_minutes'] && $minutes < $interval['end_minutes']);
        $isOccupied = fn (array $day, int $minutes): bool => collect($day['bookings'])->contains(fn (array $booking): bool => $minutes >= $booking['start_minutes'] && $minutes < $booking['end_minutes']);
    @endphp

    <div class="flex min-w-0 flex-col gap-5" wire:poll.30s>
        <div class="flex min-w-0 flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-900">
                    <button type="button" wire:click="setViewMode('week')" class="rounded-md px-3 py-1.5 text-sm font-medium {{ $viewMode === 'week' ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-600 dark:text-gray-300' }}">Неделя</button>
                    <button type="button" wire:click="setViewMode('list')" class="rounded-md px-3 py-1.5 text-sm font-medium {{ $viewMode === 'list' ? 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white' : 'text-gray-600 dark:text-gray-300' }}">Список</button>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="previousWeek" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Предыдущая неделя">
                        <x-filament::icon icon="heroicon-o-chevron-left" class="size-5" />
                    </button>
                    <span class="min-w-0 text-sm font-semibold text-gray-950 dark:text-white">{{ $this->weekLabel() }}</span>
                    <button type="button" wire:click="nextWeek" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Следующая неделя">
                        <x-filament::icon icon="heroicon-o-chevron-right" class="size-5" />
                    </button>
                    <button type="button" wire:click="today" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Сегодня</button>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                @if (count($this->specialistOptions) > 1)
                    <select wire:model.live="selectedSpecialistId" class="min-w-0 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($this->specialistOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                @endif
                <a href="{{ $this->newBookingUrl() }}" class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">Добавить запись</a>
            </div>
        </div>

        @if ($viewMode === 'list')
            <div class="min-w-0">
                {{ $this->table }}
            </div>
        @else
            <div class="hidden min-w-0 rounded-xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-gray-900">
                <div class="grid min-w-0 grid-cols-[4rem_repeat(7,minmax(0,1fr))] border-b border-gray-200 dark:border-gray-800">
                    <div class="border-r border-gray-200 dark:border-gray-800"></div>
                    @foreach ($days as $day)
                        <div class="min-w-0 border-r border-gray-200 px-2 py-3 last:border-r-0 dark:border-gray-800 {{ $day['is_today'] ? 'bg-primary-50 dark:bg-primary-950/30' : '' }}">
                            <div class="text-center text-xs font-medium {{ $day['is_today'] ? 'text-primary-700 dark:text-primary-300' : 'text-gray-500 dark:text-gray-400' }}">{{ $day['weekday'] }}</div>
                            <div class="mt-1 text-center text-sm font-semibold text-gray-950 dark:text-white">{{ $day['day_number'] }}</div>
                            <div class="mt-1 truncate text-center text-[11px] {{ $day['is_working'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-gray-500' }}">{{ $day['is_working'] ? 'Рабочий день' : 'Не работает' }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="grid min-w-0 grid-cols-[4rem_repeat(7,minmax(0,1fr))]">
                    <div class="relative border-r border-gray-200 dark:border-gray-800" style="height: {{ $calendarHeight }}px;">
                        @foreach ($grid['labels'] as $label)
                            <span class="absolute right-2 -translate-y-1/2 text-[11px] text-gray-400 dark:text-gray-500" style="{{ $minuteStyle($label['minutes']) }}">{{ $label['label'] }}</span>
                        @endforeach
                    </div>
                    @foreach ($days as $day)
                        <div class="relative min-w-0 border-r border-gray-200 last:border-r-0 dark:border-gray-800" style="height: {{ $calendarHeight }}px;">
                            @for ($minute = $grid['start']; $minute < $grid['end']; $minute += 30)
                                @php($open = $isOpen($day, $minute))
                                @php($occupied = $isOccupied($day, $minute))
                                @php($slotTime = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60))
                                @if ($open && ! $occupied)
                                    <a href="{{ $this->bookingCreationUrl($day['date'], $slotTime) }}" class="absolute inset-x-0 border-t border-gray-100 bg-white/70 transition hover:bg-primary-50 dark:border-gray-800 dark:bg-gray-900/70 dark:hover:bg-primary-950/30" style="{{ $minuteStyle($minute) }} height: {{ $rowHeight }}px;" aria-label="Добавить запись на {{ $slotTime }}"></a>
                                @else
                                    <div class="absolute inset-x-0 border-t border-gray-100 {{ $open ? 'bg-emerald-50/30 dark:bg-emerald-950/10' : 'bg-gray-50/80 dark:bg-gray-950/40' }}" style="{{ $minuteStyle($minute) }} height: {{ $rowHeight }}px;" aria-hidden="true"></div>
                                @endif
                            @endfor

                            @foreach ($day['intervals'] as $interval)
                                <div class="pointer-events-none absolute inset-x-0.5 rounded-md bg-emerald-50/40 dark:bg-emerald-950/20" style="{{ $heightStyle($interval['start_minutes'], $interval['end_minutes']) }}"></div>
                            @endforeach

                            @foreach ($day['bookings'] as $booking)
                                <a href="{{ $booking['url'] }}" class="absolute inset-x-1 z-10 min-w-0 overflow-hidden rounded-md border p-1.5 text-[11px] leading-tight shadow-sm transition hover:shadow-md {{ $booking['status_class'] }}" style="{{ $this->bookingStyle($booking) }}">
                                    <span class="block truncate font-semibold">{{ $booking['start_time'] }} · {{ $booking['client'] }}</span>
                                    <span class="block truncate">{{ $booking['service'] }}</span>
                                    <span class="block truncate opacity-80">{{ $booking['status'] }}{{ $booking['is_online'] ? ' · Онлайн' : '' }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-3 lg:hidden">
                @foreach ($days as $day)
                    <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 {{ $day['is_today'] ? 'ring-2 ring-primary-500' : '' }}">
                        <div class="flex min-w-0 items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $day['weekday'] }}, {{ $day['day_number'] }}</h2>
                                <p class="mt-1 text-xs {{ $day['is_working'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-gray-500' }}">{{ $day['is_working'] ? 'Рабочий день' : 'Не работает' }}</p>
                            </div>
                            @if ($day['is_working'])
                                <span class="min-w-0 max-w-[55%] break-words text-right text-xs leading-4 text-gray-500 dark:text-gray-400">{{ collect($day['intervals'])->map(fn (array $interval): string => $interval['start'].' – '.$interval['end'])->join(', ') }}</span>
                            @endif
                        </div>

                        @if ($day['bookings'] !== [])
                            <div class="mt-3 grid gap-2">
                                @foreach ($day['bookings'] as $booking)
                                    <a href="{{ $booking['url'] }}" class="min-w-0 rounded-lg border p-3 text-sm {{ $booking['status_class'] }}">
                                        <div class="flex min-w-0 items-start justify-between gap-2">
                                            <span class="min-w-0 truncate font-semibold">{{ $booking['client'] }}</span>
                                            <span class="shrink-0 text-xs">{{ $booking['time_range'] }}</span>
                                        </div>
                                        <div class="mt-1 truncate text-xs">{{ $booking['service'] }} · {{ $booking['status'] }}{{ $booking['is_online'] ? ' · Онлайн' : '' }}</div>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if ($day['is_working'])
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($day['intervals'] as $interval)
                                    @for ($minute = $interval['start_minutes']; $minute < $interval['end_minutes']; $minute += 30)
                                        @if (! $isOccupied($day, $minute))
                                            @php($slotTime = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60))
                                            <a href="{{ $this->bookingCreationUrl($day['date'], $slotTime) }}" class="rounded-md border border-primary-200 px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-900/60 dark:text-primary-300 dark:hover:bg-primary-950/30">{{ $slotTime }}</a>
                                        @endif
                                    @endfor
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>

            <div class="hidden" aria-hidden="true">
                {{ $this->table }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
