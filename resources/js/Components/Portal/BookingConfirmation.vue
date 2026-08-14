<script setup lang="ts">
import { computed } from 'vue';
import PortalDateTime from '../PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalLocale } from '../../types/portal';

type VisitFormat = 'office' | 'home' | 'online';

const props = defineProps<{
    serviceName: string | null;
    specialistName: string | null;
    selectedStart: string | null;
    timezone: string;
    locale: PortalLocale;
    format: VisitFormat;
    formatLabel: string;
    partySize: number;
    location: string | null;
    processing: boolean;
    error: string | undefined;
}>();

const emit = defineEmits<{
    change: [];
    confirm: [];
    'update:partySize': [value: number];
    'update:location': [value: string | null];
}>();

const { t } = usePortalLocale();
const partySize = computed({
    get: () => props.partySize,
    set: (value: number) => emit('update:partySize', value),
});
const location = computed({
    get: () => props.location ?? '',
    set: (value: string) => emit('update:location', value),
});
</script>

<template>
  <section
    class="portal-booking-confirmation portal-panel portal-stack"
    aria-labelledby="booking-confirmation-heading"
  >
    <header class="portal-stack portal-stack--tight">
      <p class="portal-eyebrow">
        {{ t('booking.stepConfirm') }}
      </p>
      <h2
        id="booking-confirmation-heading"
        class="portal-heading portal-heading--section"
      >
        {{ t('booking.checkBooking') }}
      </h2>
    </header>

    <dl class="portal-booking-summary">
      <div>
        <dt>{{ t('booking.service') }}</dt>
        <dd>{{ props.serviceName }}</dd>
      </div>
      <div>
        <dt>{{ t('booking.specialist') }}</dt>
        <dd>{{ props.specialistName }}</dd>
      </div>
      <div>
        <dt>{{ t('booking.dateTime') }}</dt>
        <dd>
          <PortalDateTime
            v-if="props.selectedStart"
            :value="props.selectedStart"
            :time-zone="props.timezone"
            :locale="props.locale"
            mode="date"
          />
          <span v-if="props.selectedStart"> · </span>
          <PortalDateTime
            v-if="props.selectedStart"
            :value="props.selectedStart"
            :time-zone="props.timezone"
            :locale="props.locale"
            mode="time"
          />
        </dd>
      </div>
      <div>
        <dt>{{ t('booking.format') }}</dt>
        <dd>{{ props.formatLabel }}</dd>
      </div>
    </dl>

    <div
      v-if="props.format === 'home'"
      class="portal-grid portal-grid--two"
    >
      <div class="portal-field">
        <label
          for="booking-party-size"
          class="portal-label"
        >{{ t('booking.partySize') }}</label>
        <input
          id="booking-party-size"
          v-model.number="partySize"
          type="number"
          min="1"
          max="20"
          required
          class="portal-input"
        >
      </div>
      <div class="portal-field">
        <label
          for="booking-location"
          class="portal-label"
        >{{ t('booking.address') }}</label>
        <input
          id="booking-location"
          v-model="location"
          type="text"
          maxlength="500"
          required
          class="portal-input"
        >
      </div>
    </div>

    <p
      v-if="props.error"
      class="portal-notice portal-notice--error"
      role="alert"
    >
      {{ props.error }}
    </p>

    <div class="portal-action-row">
      <button
        type="button"
        class="portal-button portal-button--secondary"
        @click="emit('change')"
      >
        {{ t('booking.changeTime') }}
      </button>
      <button
        type="button"
        class="portal-button portal-button--primary"
        :disabled="props.processing"
        @click="emit('confirm')"
      >
        {{ props.processing ? t('booking.creating') : t('booking.create') }}
      </button>
    </div>
  </section>
</template>
