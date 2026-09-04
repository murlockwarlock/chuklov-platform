<x-filament-panels::page>
    <div
        x-data="{
            deviceTimezone: null,
            dismissed: false,
            currentTimezone: @js($this->currentViewerTimezone()),
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
            <span x-text="'Мы определили ваш часовой пояс как '+deviceTimezone+'.'"></span>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-lg bg-amber-600 px-3 py-2 font-medium text-white" @click="useDevice()">Использовать <span x-text="deviceTimezone"></span></button>
                <button type="button" class="rounded-lg border border-amber-400 px-3 py-2" @click="dismiss()">Оставить <span x-text="currentTimezone"></span></button>
                <button type="button" class="rounded-lg border border-transparent px-3 py-2 underline" @click="dismissed = true; document.querySelector('[name=\'data[viewer_timezone]\']')?.focus()">Выбрать другой</button>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            Время: <span class="font-semibold">{{ $this->currentViewerTimezone() }}</span>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
