<x-filament-panels::page>
    <div
        x-data="{
            deviceTimezone: null,
            dismissed: false,
            currentTimezone: @js($this->currentViewerTimezone()),
            timezoneLabel(timezone) {
                const labels = {
                    UTC: 'UTC',
                    'Asia/Almaty': 'Алматы',
                    'Asia/Bangkok': 'Бангкок',
                    'Europe/Berlin': 'Берлин',
                    'Europe/London': 'Лондон',
                    'Europe/Moscow': 'Москва',
                    'Asia/Tokyo': 'Токио',
                }
                const city = labels[timezone] || timezone.split('/').pop().replaceAll('_', ' ')
                let offset = 'UTC+00:00'
                try {
                    const part = new Intl.DateTimeFormat('en-US', { timeZone: timezone, timeZoneName: 'longOffset' })
                        .formatToParts(new Date())
                        .find((item) => item.type === 'timeZoneName')?.value
                    if (part && part !== 'GMT') offset = part.replace('GMT', 'UTC')
                } catch (error) {}

                return `${city} (${offset})`
            },
            currentTimezoneLabel() {
                return this.timezoneLabel(this.currentTimezone)
            },
            init() {
                this.deviceTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || null
                this.dismissed = this.deviceTimezone !== null
                    && localStorage.getItem('chuklov-specialist-timezone-dismissed:'+this.currentTimezone+':'+this.deviceTimezone) === '1'
            },
            dismiss() {
                if (this.deviceTimezone !== null) {
                    localStorage.setItem('chuklov-specialist-timezone-dismissed:'+this.currentTimezone+':'+this.deviceTimezone, '1')
                }
                this.dismissed = true
                $wire.dismissDeviceTimezone()
            },
            useDevice() {
                $wire.useDeviceTimezone(this.deviceTimezone)
                this.dismissed = true
            },
        }"
        x-init="init()"
        class="flex flex-col gap-4"
    >
        <div
            x-cloak
            x-show="deviceTimezone !== null && deviceTimezone !== currentTimezone && !dismissed"
            class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100 sm:flex-row sm:items-center sm:justify-between"
        >
            <span>{{ __('Мы определили ваш часовой пояс как') }} <span x-text="timezoneLabel(deviceTimezone)"></span>.</span>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-lg bg-amber-600 px-3 py-2 font-medium text-white" @click="useDevice()">{{ __('Использовать') }} <span x-text="timezoneLabel(deviceTimezone)"></span></button>
                <button type="button" class="rounded-lg border border-amber-400 px-3 py-2" @click="dismiss()">{{ __('Оставить') }} <span x-text="currentTimezoneLabel()"></span></button>
                <button type="button" class="rounded-lg border border-transparent px-3 py-2 underline" @click="dismissed = true; document.querySelector('[name=\'data[viewer_timezone]\']')?.focus()">{{ __('Выбрать другой') }}</button>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            {{ __('Время') }}: <span class="font-semibold">{{ \App\Filament\Support\TimezoneOptions::display($this->currentViewerTimezone()) }}</span>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
