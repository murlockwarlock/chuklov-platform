<script setup lang="ts">
import PortalDateTime from '../PortalDateTime.vue';
import PortalIcon from './PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalLocale } from '../../types/portal';

defineProps<{ starts: string[]; timezone: string; locale: PortalLocale; canAdd: boolean }>();
defineEmits<{ add: []; remove: [start: string] }>();
const { t } = usePortalLocale();
</script>

<template>
  <div class="portal-stack">
    <ol
      v-if="starts.length"
      class="portal-stack portal-stack--tight"
      :aria-label="t('booking.selectedSessions')"
    >
      <li
        v-for="start in starts"
        :key="start"
        class="portal-cluster"
        data-testid="queued-booking-session"
      >
        <PortalDateTime
          :value="start"
          :time-zone="timezone"
          :locale="locale"
        />
        <button
          type="button"
          class="portal-link portal-link--button"
          @click="$emit('remove', start)"
        >
          {{ t('booking.removeSession') }}
        </button>
      </li>
    </ol>
    <button
      type="button"
      class="portal-button portal-button--secondary"
      :disabled="!canAdd"
      data-testid="booking-add-session"
      @click="$emit('add')"
    >
      <PortalIcon name="plus" />
      {{ t('booking.addSession') }}
    </button>
  </div>
</template>
