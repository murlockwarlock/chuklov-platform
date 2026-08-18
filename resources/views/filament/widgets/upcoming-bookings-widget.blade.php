@php
    $data = $this->getData();
@endphp

<div class="fi-wi-upcoming-bookings w-full">
    @if($data)
        <div class="bg-white dark:bg-gray-900 border border-slate-200 dark:border-white/10 rounded-[8px] p-4 sm:p-6 shadow-sm">
            {{-- Integrated Dashboard Section Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-200/80 dark:border-white/10">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white tracking-tight">
                        Ближайшие записи
                    </h2>
                    <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-gray-400 mt-0.5 font-medium">
                        <span>Сегодня: <strong class="text-slate-900 dark:text-white font-mono">{{ $data->todayCount }}</strong></span>
                        <span>·</span>
                        <span>Завтра: <strong class="text-slate-900 dark:text-white font-mono">{{ $data->tomorrowCount }}</strong></span>
                    </div>
                </div>
                <div>
                    <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index') }}" class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 inline-flex items-center gap-1 hover:underline">
                        Все записи на приём →
                    </a>
                </div>
            </div>

            {{-- 4-Day Horizon Grid across full available desktop width --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 lg:gap-6 mt-5 items-start">
                @foreach($data->days as $day)
                    <div class="flex flex-col gap-3 min-w-0 {{ ! $loop->last ? 'lg:border-r lg:border-slate-100 lg:dark:border-white/5 lg:pr-6' : '' }}">
                        {{-- Day Column Header --}}
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                            <span class="text-xs font-bold uppercase tracking-wider {{ $day->isToday ? 'text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-gray-300' }}">
                                {{ $day->label }}
                            </span>
                            <span class="text-[11px] font-mono font-medium text-slate-500 dark:text-gray-400 bg-slate-100 dark:bg-white/5 px-2 py-0.5 rounded-[4px]">
                                {{ $day->totalCount }}
                            </span>
                        </div>

                        {{-- Bookings List --}}
                        <div class="space-y-2.5">
                            @forelse($day->bookings as $booking)
                                @php
                                    $time = \Carbon\Carbon::parse($booking->starts_at)->setTimezone($data->timezone)->format('H:i');
                                    $statusLabel = \App\Filament\Widgets\UpcomingBookingsWidget::statusLabel($booking->status);
                                    $statusColor = \App\Filament\Widgets\UpcomingBookingsWidget::statusColor($booking->status);
                                    $formatLabel = \App\Filament\Widgets\UpcomingBookingsWidget::formatLabel($booking->visit_format);
                                @endphp
                                <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $booking->id]) }}"
                                   class="block bg-slate-50/70 dark:bg-white/[0.03] hover:bg-slate-100/90 dark:hover:bg-white/[0.06] border border-slate-200/80 dark:border-white/10 hover:border-amber-400/80 dark:hover:border-amber-500/80 rounded-[6px] p-3 transition duration-150 group shadow-none min-w-0">
                                    {{-- Row 1: Time & Format --}}
                                    <div class="flex items-center justify-between gap-2 mb-1.5">
                                        <span class="font-mono text-sm font-bold text-slate-900 dark:text-white group-hover:text-amber-600 dark:group-hover:text-amber-400 transition">
                                            {{ $time }}
                                        </span>
                                        <span class="text-[10px] font-medium text-slate-500 dark:text-gray-400 bg-white dark:bg-gray-800 border border-slate-200/80 dark:border-white/10 px-1.5 py-0.5 rounded-[3px] shrink-0">
                                            {{ $formatLabel }}
                                        </span>
                                    </div>

                                    {{-- Row 2: Status Badge (full text, no clipping) --}}
                                    <div class="mb-2">
                                        <span class="inline-block text-[10px] font-medium px-2 py-0.5 rounded-[4px] border leading-normal break-words {{ $statusColor }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </div>

                                    {{-- Row 3: Client Name (no truncate, wraps) --}}
                                    <div class="text-xs font-semibold text-slate-900 dark:text-white break-words mb-1 leading-snug">
                                        {{ $booking->client?->full_name ?: ('Клиент #' . $booking->client_id) }}
                                    </div>

                                    {{-- Row 4: Service Name (no truncate, wraps) --}}
                                    <div class="text-xs text-slate-600 dark:text-gray-300 break-words mb-1.5 leading-snug">
                                        {{ $booking->service?->name ?: 'Услуга' }}
                                    </div>

                                    {{-- Row 5: Specialist (subtle top divider, secondary text) --}}
                                    <div class="text-[11px] text-slate-500 dark:text-gray-400 pt-1.5 border-t border-slate-200/60 dark:border-white/5 break-words">
                                        {{ $booking->specialist?->display_name ?: 'Специалист' }}
                                    </div>
                                </a>
                            @empty
                                <div class="py-8 text-center text-xs text-slate-400 dark:text-gray-500 bg-slate-50/40 dark:bg-white/[0.01] rounded-[6px] border border-dashed border-slate-200/60 dark:border-white/5">
                                    Записей нет
                                </div>
                            @endforelse

                            @if($day->totalCount > 10)
                                <div class="text-center pt-1">
                                    <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index') }}" class="text-xs font-medium text-amber-600 dark:text-amber-400 hover:underline">
                                        + ещё {{ $day->totalCount - 10 }} записей →
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
