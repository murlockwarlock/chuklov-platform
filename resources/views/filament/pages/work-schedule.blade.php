<x-filament-panels::page>
    @php
        $days = $this->scheduleDays;
        $cells = $this->monthCells;
        $weekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
    @endphp

    <div class="flex min-w-0 flex-col gap-6">
        <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="text-sm text-gray-500 dark:text-gray-400">Повторяющийся график и изменения на отдельные даты</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Время специалиста: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $this->specialistScheduleTimezone() }}</span></p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Календарь CRM: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $this->crmTimezone() }}</span></p>
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

        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)]">
            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Базовый график</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Выберите дни недели и сохраните общий интервал.</p>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Применить к месяцу</p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="applyPreset('weekdays')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Будни</button>
                        <button type="button" wire:click="applyPreset('all')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Все дни</button>
                        <button type="button" wire:click="applyPreset('even')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Чётные</button>
                        <button type="button" wire:click="applyPreset('odd')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Нечётные</button>
                        <button type="button" wire:click="applyPreset('clear')" class="col-span-2 rounded-lg border border-transparent px-3 py-2 text-sm font-medium text-primary-600 underline decoration-primary-300 underline-offset-4 hover:text-primary-700 dark:text-primary-400">Очистить</button>
                    </div>
                    @if ($this->pendingPresetLabel() !== null)
                        <p class="mt-3 rounded-lg bg-primary-50 px-3 py-2 text-sm text-primary-800 dark:bg-primary-950/30 dark:text-primary-200">Выбрано: {{ $this->pendingPresetLabel() }}. Нажмите «Сохранить график».</p>
                    @endif
                </div>

                <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($weekdays as $weekday => $label)
                        <label class="flex min-w-0 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-800">
                            <input type="checkbox" value="{{ $weekday }}" wire:model.live="selectedWeekdays" class="rounded border-gray-300 text-primary-600 shadow-sm dark:border-gray-700">
                            <span class="truncate">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                        С
                        <input type="time" wire:model.live="startTime" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    </label>
                    <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                        До
                        <input type="time" wire:model.live="endTime" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    </label>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.live="breakEnabled" class="rounded border-gray-300 text-primary-600 shadow-sm dark:border-gray-700">
                    <span>Перерыв</span>
                </label>

                @if ($breakEnabled)
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                            Начало перерыва
                            <input type="time" wire:model.live="breakStart" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        </label>
                        <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                            Конец перерыва
                            <input type="time" wire:model.live="breakEnd" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        </label>
                    </div>
                @endif

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
                        <label class="mt-3 flex items-start gap-2">
                            <input type="checkbox" wire:model.live="acknowledgeImpact" class="mt-0.5 rounded border-amber-400 text-amber-600 shadow-sm">
                            <span>Подтверждаю изменение графика. Записи сохранятся и потребуют отдельного решения.</span>
                        </label>
                    </div>
                @endif

                <button type="button" wire:click="saveSchedule" wire:loading.attr="disabled" class="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-60">{{ $this->hasPendingImpact() ? 'Подтвердить и сохранить' : 'Сохранить график' }}</button>
            </section>

            <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <button type="button" wire:click="previousMonth" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Предыдущий месяц">←</button>
                        <h2 class="min-w-0 text-base font-semibold text-gray-950 dark:text-white">{{ $this->monthLabel() }}</h2>
                        <button type="button" wire:click="nextMonth" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" aria-label="Следующий месяц">→</button>
                    </div>
                    <button type="button" wire:click="currentMonth" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Текущий месяц</button>
                </div>

                <div class="mt-4 grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 text-center dark:border-gray-800 dark:bg-gray-800">
                    @foreach ($weekdays as $weekday => $label)
                        <div class="min-w-0 bg-gray-50 px-1 py-2 text-xs font-medium {{ $weekday > 5 ? 'text-rose-600 dark:text-rose-300' : 'text-gray-500 dark:text-gray-400' }} dark:bg-gray-950">{{ $label }}</div>
                    @endforeach
                    @foreach ($cells as $cell)
                        @if ($cell === null)
                            <div class="min-h-24 min-w-0 bg-gray-50/70 dark:bg-gray-950/50"></div>
                        @else
                            <button type="button" wire:click="editDate('{{ $cell['date'] }}')" class="min-h-24 min-w-0 bg-white p-2 text-left transition hover:bg-primary-50 dark:bg-gray-900 dark:hover:bg-primary-950/30 {{ $this->isToday($cell['date']) ? 'ring-2 ring-inset ring-primary-500' : '' }} {{ $selectedDate === $cell['date'] ? 'bg-primary-50 dark:bg-primary-950/50' : '' }} {{ $this->isPresetWorkingDate($cell['date']) ? 'ring-2 ring-inset ring-emerald-400' : '' }}">
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ (int) substr($cell['date'], -2) }}</span>
                                @if ($cell['is_working'])
                                    <span class="mt-2 block truncate text-[11px] font-medium text-emerald-700 dark:text-emerald-300">Работает</span>
                                    @foreach ($cell['intervals'] as $interval)
                                        <span class="mt-1 block truncate text-[11px] text-gray-500 dark:text-gray-400">{{ $interval['start'] }}–{{ $interval['end'] }}</span>
                                    @endforeach
                                @elseif ($cell['exception_type'] === 'day_off')
                                    <span class="mt-2 block truncate text-[11px] font-medium text-amber-700 dark:text-amber-300">Выходной</span>
                                @else
                                    <span class="mt-2 block truncate text-[11px] text-gray-400 dark:text-gray-500">Не работает</span>
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>
            </section>
        </div>

        @if ($selectedDate !== null)
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex min-w-0 flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Изменение на {{ Carbon\CarbonImmutable::parse($selectedDate)->format('d.m.Y') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Изменение действует только для этой даты и имеет приоритет над повторяющимся графиком.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="saveOverride" class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500">Сохранить изменение</button>
                        @if ($selectedExceptionId !== null)
                            <button type="button" wire:click="$set('overrideType', 'working')" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Вернуть график</button>
                        @endif
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-3">
                    <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                        Режим
                        <select wire:model.live="overrideType" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            <option value="working">По графику</option>
                            <option value="day_off">Выходной</option>
                            <option value="custom_window">Своё время</option>
                        </select>
                    </label>
                    @if ($overrideType === 'custom_window')
                        <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                            С
                            <input type="time" wire:model.live="overrideStart" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        </label>
                        <label class="min-w-0 text-sm font-medium text-gray-950 dark:text-white">
                            До
                            <input type="time" wire:model.live="overrideEnd" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        </label>
                    @endif
                </div>

                <label class="mt-4 block text-sm font-medium text-gray-950 dark:text-white">
                    Причина <span class="font-normal text-gray-400">необязательно</span>
                    <input type="text" wire:model.live="overrideReason" maxlength="500" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </label>
            </section>
        @endif
    </div>
</x-filament-panels::page>
