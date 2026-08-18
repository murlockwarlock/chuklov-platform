@php
    $data = $this->getData();
@endphp

<div class="fi-wi-upcoming-bookings space-y-4">
    @if($data)
        {{-- Header bar with counts and quick link --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white border border-slate-200 rounded-[6px] p-3 sm:px-4">
            <div class="flex items-center gap-4 text-xs">
                <span class="font-semibold text-slate-900 tracking-tight">Расписание:</span>
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[4px] bg-slate-100 text-slate-700 font-medium">
                    Сегодня: <strong class="text-slate-900 font-mono">{{ $data->todayCount }}</strong>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-[4px] bg-slate-100 text-slate-700 font-medium">
                    Завтра: <strong class="text-slate-900 font-mono">{{ $data->tomorrowCount }}</strong>
                </span>
            </div>
            <div>
                <a href="{{ url('/admin/bookings') }}" class="text-xs font-medium text-amber-600 hover:text-amber-700 hover:underline inline-flex items-center gap-1">
                    Все записи на приём →
                </a>
            </div>
        </div>

        {{-- 4-Day Board --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-start">
            @foreach($data->days as $day)
                <div class="bg-slate-50/70 border border-slate-200 rounded-[6px] p-3 flex flex-col gap-2.5 min-w-0">
                    {{-- Day Column Header --}}
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                        <span class="text-xs font-semibold {{ $day->isToday ? 'text-amber-700' : 'text-slate-800' }}">
                            {{ $day->label }}
                        </span>
                        <span class="text-[11px] font-mono text-slate-500 bg-white border border-slate-200 px-1.5 py-0.2 rounded-[3px]">
                            {{ $day->totalCount }}
                        </span>
                    </div>

                    {{-- Bookings List --}}
                    <div class="space-y-2">
                        @forelse($day->bookings as $booking)
                            @php
                                $time = \Carbon\Carbon::parse($booking->starts_at)->setTimezone($data->timezone)->format('H:i');
                                $statusLabel = \App\Filament\Widgets\UpcomingBookingsWidget::statusLabel($booking->status);
                                $statusColor = \App\Filament\Widgets\UpcomingBookingsWidget::statusColor($booking->status);
                                $formatLabel = \App\Filament\Widgets\UpcomingBookingsWidget::formatLabel($booking->visit_format);
                            @endphp
                            <a href="{{ url('/admin/bookings/' . $booking->id) }}"
                               class="block bg-white border border-slate-200 hover:border-amber-400/80 rounded-[4px] p-2.5 transition group shadow-none">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="font-mono text-xs font-bold text-slate-900 group-hover:text-amber-600 transition">
                                        {{ $time }}
                                    </span>
                                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-[3px] border {{ $statusColor }}">
                                        {{ $statusLabel }}
                                    </span>
                                </div>
                                <div class="text-xs font-semibold text-slate-900 truncate mb-0.5" title="{{ $booking->client?->full_name }}">
                                    {{ $booking->client?->full_name ?: 'Клиент #' . $booking->client_id }}
                                </div>
                                <div class="text-[11px] text-slate-600 truncate mb-1" title="{{ $booking->service?->name }}">
                                    {{ $booking->service?->name ?: 'Услуга' }}
                                </div>
                                <div class="flex items-center justify-between text-[10px] text-slate-500 pt-1 border-t border-slate-100">
                                    <span class="truncate max-w-[65%]" title="{{ $booking->specialist?->display_name }}">
                                        {{ $booking->specialist?->display_name ?: 'Специалист' }}
                                    </span>
                                    <span class="text-slate-400">
                                        {{ $formatLabel }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="py-6 text-center text-xs text-slate-400">
                                Записей нет
                            </div>
                        @endforelse

                        @if($day->totalCount > 10)
                            <div class="text-center pt-1">
                                <a href="{{ url('/admin/bookings') }}" class="text-[11px] text-amber-600 hover:underline">
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
