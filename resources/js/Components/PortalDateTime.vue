<script setup lang="ts">
import { computed } from 'vue';
import {
    formatPortalDate,
    formatPortalDateTime,
    formatPortalTime,
    type PortalDateTimePreferences,
} from '../utils/dateTime';
import type { PortalLocale } from '../types/portal';

const props = withDefaults(defineProps<{
    value: string | Date | null;
    timeZone: string;
    locale?: PortalLocale;
    mode?: 'date' | 'time' | 'datetime';
    preferences?: Partial<PortalDateTimePreferences>;
}>(), {
    locale: 'ru',
    mode: 'datetime',
    preferences: () => ({}),
});

const formatted = computed(() => {
    const preferences = {
        locale: props.locale === 'en' ? 'en-GB' : 'ru-RU',
        dateOrder: 'day-month-year' as const,
        dateSeparator: '.',
        timeCycle: 'h23' as const,
        ...props.preferences,
    };

    if (props.mode === 'date') {
        return formatPortalDate(props.value, props.timeZone, preferences);
    }

    if (props.mode === 'time') {
        return formatPortalTime(props.value, props.timeZone, preferences);
    }

    return formatPortalDateTime(props.value, props.timeZone, preferences);
});
</script>

<template>
  <time :datetime="typeof props.value === 'string' ? props.value : undefined">
    {{ formatted }}
  </time>
</template>
