<x-filament-panels::page>
    @php
        $cells = $this->monthCells;
        $weekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
    @endphp

    <div class="flex min-w-0 flex-col gap-6">
        <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="text-sm text-gray-500 dark:text-gray-400">Календарь конкретных дат. Регулярный график задаётся в «Настройках расписания».</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $this->specialistScheduleTimezoneLabel() }}</p>
            </div>
            <div class="w-full lg:max-w-xs">
                <label for="work-schedule-specialist" class="fi-fo-field-wrp-label inline-flex text-sm font-medium text-gray-950 dark:text-white">Специалист</label>
                <select id="work-schedule-specialist" wire:model.live="specialistId" class="fi-select-input mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach ($this->specialistOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex min-w-0 flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 items-center gap-2">
                    <button type="button" wire:click="previousMonth" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Предыдущий месяц">
                        <x-filament::icon icon="heroicon-o-chevron-left" class="size-5" />
                    </button>
                    <h2 class="min-w-0 text-base font-semibold text-gray-950 dark:text-white">{{ $this->monthLabel() }}</h2>
                    <button type="button" wire:click="nextMonth" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Следующий месяц">
                        <x-filament::icon icon="heroicon-o-chevron-right" class="size-5" />
                    </button>
                    <button type="button" wire:click="currentMonth" class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Текущий месяц</button>
                </div>

                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Выбрано: <span class="font-semibold text-gray-950 dark:text-white">{{ count($selectedDates) }}</span></span>
                    <button type="button" wire:click="applyPreset('weekdays')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Будни</button>
                    <button type="button" wire:click="applyPreset('all')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Все дни</button>
                    <button type="button" wire:click="applyPreset('even')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Чётные</button>
                    <button type="button" wire:click="applyPreset('odd')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Нечётные</button>
                    <button type="button" wire:click="clearSelectedDates" @disabled($selectedDates === []) class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-900/60 dark:text-rose-300 dark:hover:bg-rose-950/30">Очистить</button>
                </div>
            </div>

            @if ($this->pendingPresetLabel() !== null)
                <p class="mt-4 rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-800 dark:bg-primary-950/30 dark:text-primary-200">{{ $this->pendingPresetLabel() }}: выбраны даты текущего месяца.</p>
            @endif

            <div class="mt-4 grid min-w-0 grid-cols-7 gap-px rounded-lg border border-gray-200 bg-gray-200 text-center dark:border-gray-800 dark:bg-gray-800">
                @foreach ($weekdays as $weekday => $label)
                    <div class="min-w-0 bg-gray-50 px-1 py-2 text-xs font-medium {{ $weekday > 5 ? 'text-rose-600 dark:text-rose-300' : 'text-gray-500 dark:text-gray-400' }} dark:bg-gray-950">{{ $label }}</div>
                @endforeach
                @foreach ($cells as $cell)
                    @if ($cell === null)
                        <div class="min-h-28 min-w-0 bg-gray-50/70 dark:bg-gray-950/50"></div>
                    @else
                        <button
                            type="button"
                            wire:click="toggleDate('{{ $cell['date'] }}')"
                            aria-pressed="{{ $this->isSelectedDate($cell['date']) ? 'true' : 'false' }}"
                            data-today="{{ $this->isToday($cell['date']) ? 'true' : 'false' }}"
                            class="min-h-28 min-w-0 bg-white p-2 text-left transition hover:bg-primary-50 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:bg-gray-900 dark:hover:bg-primary-950/30 {{ $this->isToday($cell['date']) && ! $this->isSelectedDate($cell['date']) ? 'ring-1 ring-inset ring-gray-400 dark:ring-gray-500' : '' }} {{ $this->isSelectedDate($cell['date']) ? 'bg-primary-100 ring-2 ring-inset ring-primary-600 dark:bg-primary-950/60 dark:ring-primary-400' : '' }}"
                        >
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ (int) substr($cell['date'], -2) }}</span>
                            @if ($this->isToday($cell['date']))
                                <span class="mt-1 inline-flex items-center gap-1 text-[10px] font-medium text-gray-500 dark:text-gray-400" aria-label="Сегодня">
                                    <span class="size-1.5 rounded-full bg-gray-400 dark:bg-gray-500"></span>
                                    Сегодня
                                </span>
                            @endif
                            @if ($cell['is_working'])
                                <span class="mt-2 block text-[11px] font-medium text-emerald-700 dark:text-emerald-300">Работает</span>
                                @foreach ($cell['intervals'] as $interval)
                                    <span class="mt-1 block break-words text-[10px] leading-3 text-gray-600 dark:text-gray-400 sm:truncate sm:whitespace-nowrap">{{ $interval['start'] }} – {{ $interval['end'] }}</span>
                                @endforeach
                            @elseif ($cell['exception_type'] === 'day_off')
                                <span class="mt-2 block text-[11px] font-medium text-amber-700 dark:text-amber-300">Выходной</span>
                            @else
                                <span class="mt-2 block text-[11px] text-gray-400 dark:text-gray-500">Не работает</span>
                            @endif
                        </button>
                    @endif
                @endforeach
            </div>
        </section>

        @if ($selectedDates !== [])
            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Изменение выбранных дат</h2>
                        <p class="mt-1 break-words text-sm text-gray-500 dark:text-gray-400">{{ $this->selectedDatesLabel() }}. Изменение действует только для выбранных дат и не меняет регулярный график.</p>
                    </div>
                    <div class="flex min-w-0 flex-wrap gap-2">
                        <button type="button" wire:click="saveOverride" wire:loading.attr="disabled" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 disabled:opacity-60">Сохранить изменения</button>
                        <button type="button" wire:click="clearSelectedDates" class="rounded-lg border border-rose-200 px-4 py-2.5 text-sm font-medium text-rose-700 hover:bg-rose-50 dark:border-rose-900/60 dark:text-rose-300 dark:hover:bg-rose-950/30">Очистить даты</button>
                        <button type="button" wire:click="returnToRegularSchedule" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Вернуть по графику</button>
                    </div>
                </div>

                <div class="mt-5 grid min-w-0 gap-4 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                    <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                        Действие
                        <select wire:model.live="overrideType" class="mt-2 block w-full min-w-0 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            <option value="working">По регулярному графику</option>
                            <option value="day_off">Выходной</option>
                            <option value="custom_window">Своё рабочее время</option>
                        </select>
                    </label>

                    @if ($overrideType === 'custom_window')
                        <div class="min-w-0">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">Рабочие интервалы</p>
                                <button type="button" wire:click="addOverrideInterval" class="shrink-0 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">+ Добавить интервал</button>
                            </div>
                            <div class="mt-2 grid min-w-0 gap-2">
                                @forelse ($overrideIntervals as $index => $interval)
                                    <div wire:key="override-interval-{{ $index }}" class="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2">
                                        <label class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                                            С
                                            <input type="time" wire:model.live="overrideIntervals.{{ $index }}.start_time" class="mt-1 block w-full min-w-0 rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        </label>
                                        <label class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                                            До
                                            <input type="time" wire:model.live="overrideIntervals.{{ $index }}.end_time" class="mt-1 block w-full min-w-0 rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        </label>
                                        <button type="button" wire:click="removeOverrideInterval({{ $index }})" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="Удалить интервал">
                                            <x-filament::icon icon="heroicon-o-trash" class="size-4" />
                                        </button>
                                    </div>
                                @empty
                                    <p class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Добавьте хотя бы один рабочий интервал.</p>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <p class="self-end rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-gray-950 dark:text-gray-400">{{ $overrideType === 'day_off' ? 'Рабочее время будет убрано только у выбранных дат.' : 'Локальное изменение будет удалено, и даты снова возьмут регулярный график.' }}</p>
                    @endif
                </div>

                <label class="mt-4 block min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                    Причина <span class="font-normal text-gray-400">необязательно</span>
                    <input type="text" wire:model.live="overrideReason" maxlength="500" class="mt-2 block w-full min-w-0 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </label>

                @if ($errorMessage !== '')
                    <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{{ $errorMessage }}</div>
                @endif

                @if ($this->hasPendingImpact())
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-900/70 dark:bg-amber-950/30 dark:text-amber-100">
                        <p class="font-medium">Изменение затрагивает будущие записи</p>
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach ($impactBookings as $booking)
                                <li>{{ $booking['client'] ?? 'Клиент' }} · {{ $booking['service'] ?? 'Услуга' }} · {{ $booking['local_start'] ?? 'Дата не указана' }}</li>
                            @endforeach
                        </ul>
                        <label class="mt-3 flex min-w-0 items-start gap-2">
                            <input type="checkbox" wire:model.live="acknowledgeImpact" class="mt-0.5 shrink-0 rounded border-amber-400 text-amber-600 shadow-sm">
                            <span>Подтверждаю изменение графика. Записи сохранятся и потребуют отдельного решения.</span>
                        </label>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
