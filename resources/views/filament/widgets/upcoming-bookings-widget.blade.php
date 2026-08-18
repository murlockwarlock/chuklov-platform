@php
    $data = $this->getData();
@endphp

<div class="fi-wi-upcoming-bookings space-y-4">
    @if($data)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white dark:bg-gray-900 border border-slate-200 dark:border-white/10 rounded-[6px] p-3 sm:px-4">
            <div class="flex items-center gap-4 text-xs">
                <span class="font-semibold text-slate-900 dark:text-white tracking-tight">Расписание:</span>
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[4px] bg-slate-100 dark:bg-white/5 text-slate-700 dark:text-gray-300 font-medium">
                    Сегодня: <strong class="text-slate-900 dark:text-white font-mono">{{ $data->todayCount }}</strong>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[4px] bg-slate-100 dark:bg-white/5 text-slate-700 dark:text-gray-300 font-medium">
                    Завтра: <strong class="text-slate-900 dark:text-white font-mono">{{ $data->tomorrowCount }}</strong>
                </span>
            </div>
            <div>
                <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index') }}" class="text-xs font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 hover:underline inline-flex items-center gap-1">
                    Все записи на приём →
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-start">
            @foreach($data->days as $day)
                <div class="bg-slate-50/70 dark:bg-gray-900/60 border border-slate-200 dark:border-white/10 rounded-[6px] p-3 flex flex-col gap-2.5 min-w-0">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-white/10">
                        <span class="text-xs font-semibold {{ $day->isToday ? 'text-amber-700 dark:text-amber-400' : 'text-slate-800 dark:text-gray-200' }}">
                            {{ $day->label }}
                        </span>
                        <span class="text-[11px] font-mono text-slate-500 dark:text-gray-400 bg-white dark:bg-gray-800 border border-slate-200 dark:border-white/10 px-1.5 py-0.2 rounded-[3px]">
                            {{ $day->totalCount }}
                        </span>
                    </div>

                    <div class="space-y-2">
                        @forelse($day->bookings as $booking)
                            @php
                                $time = \Carbon\Carbon::parse($booking->starts_at)->setTimezone($data->timezone)->format('H:i');
                                $statusLabel = \App\Filament\Widgets\UpcomingBookingsWidget::statusLabel($booking->status);
                                $statusColor = \App\Filament\Widgets\UpcomingBookingsWidget::statusColor($booking->status);
                                $formatLabel = \App\Filament\Widgets\UpcomingBookingsWidget::formatLabel($booking->visit_format);
                            @endphp
                            <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $booking->id]) }}"
                               class="block bg-white dark:bg-gray-900 border border-slate-200 dark:border-white/10 hover:border-amber-400/80 dark:hover:border-amber-500/80 rounded-[4px] p-2.5 transition group shadow-none min-w-0 overflow-hidden">
                                <div class="flex flex-col gap-1.5 mb-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="font-mono text-xs font-bold text-slate-900 dark:text-white group-hover:text-amber-600 dark:group-hover:text-amber-400 transition shrink-0">
                                            {{ $time }}
                                        </span>
                                        <span class="text-[10px] text-slate-400 dark:text-gray-400 shrink-0 font-medium">
                                            {{ $formatLabel }}
                                        </span>
                                    </div>
                                    <div class="min-w-0">
                                        <span class="inline-block text-[10px] font-medium px-1.5 py-0.5 rounded-[3px] border leading-normal break-words {{ $statusColor }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-xs font-semibold text-slate-900 dark:text-white truncate mb-0.5" title="{{ $booking->client?->full_name }}">
                                    {{ $booking->client?->full_name ?: 'Клиент #' . $booking->client_id }}
                                </div>
                                <div class="text-[11px] text-slate-600 dark:text-gray-300 truncate mb-1" title="{{ $booking->service?->name }}">
                                    {{ $booking->service?->name ?: 'Услуга' }}
                                </div>
                                <div class="text-[10px] text-slate-500 dark:text-gray-400 pt-1 border-t border-slate-100 dark:border-white/5 truncate" title="{{ $booking->specialist?->display_name }}">
                                    {{ $booking->specialist?->display_name ?: 'Специалист' }}
                                </div>
                            </a>
                        @empty
                            <div class="py-6 text-center text-xs text-slate-400 dark:text-gray-500">
                                Записей нет
                            </div>
                        @endforelse

                        @if($day->totalCount > 10)
                            <div class="text-center pt-1">
                                <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('index') }}" class="text-[11px] text-amber-600 dark:text-amber-400 hover:underline">
                                    + ещё {{ $day->totalCount - 10 }} записей →
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
