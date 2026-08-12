<script setup lang="ts">
import { computed } from 'vue';
import {
    formatPortalDateTime,
    type PortalDateTimePreferences,
} from '../utils/dateTime';

const props = withDefaults(defineProps<{
    value: string | Date | null;
    timeZone: string;
    preferences?: Partial<PortalDateTimePreferences>;
}>(), {
    preferences: () => ({}),
});

const formatted = computed(() => formatPortalDateTime(props.value, props.timeZone, props.preferences));
</script>

<template>
  <time :datetime="typeof props.value === 'string' ? props.value : undefined">
    {{ formatted }}
  </time>
</template>
