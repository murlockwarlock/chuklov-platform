<script setup lang="ts">
import { computed } from 'vue';
import LegalConsentChecklist from './LegalConsentChecklist.vue';
import PortalDateTime from '../PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalLocale } from '../../types/portal';

type VisitFormat = 'office' | 'home' | 'online';

type WorkingLocation = {
    name: string;
    address: string;
    timezone: string;
} | null;

type LegalDocument = {
    id: number;
    documentType: string;
    title: string;
    content: string;
    contentHtml: string;
    version: string;
    isRequired: boolean;
};

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
    workingLocation: WorkingLocation;
    locationArea: string | null;
    hasLocationDayRules: boolean;
    locationAreaOptions: string[];
    processing: boolean;
    error: string | undefined;
    legalDocuments: LegalDocument[];
    consentValues: Record<number, boolean>;
    marketingConsent: boolean;
    showMarketing: boolean;
    attributionSources: string[];
    attributionSource: string | null;
    attributionNeedsManualSource: boolean;
    requiredAcceptanceError?: string;
}>();

const emit = defineEmits<{
    change: [];
    confirm: [];
    'update:partySize': [value: number];
    'update:location': [value: string | null];
    'update:consent': [id: number, granted: boolean];
    'update:marketingConsent': [granted: boolean];
    'update:attributionSource': [source: string | null];
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
      <div
        v-if="props.hasLocationDayRules"
        class="portal-field"
      >
        <span class="portal-label">{{ t('booking.area') }}</span>
        <span class="portal-input portal-input--static">{{ props.locationArea }}</span>
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

    <section
      v-if="props.format === 'office' && props.workingLocation"
      class="portal-booking-location-panel portal-stack portal-stack--tight"
      aria-labelledby="booking-office-location-heading"
    >
      <h3
        id="booking-office-location-heading"
        class="portal-heading portal-heading--card"
      >{{ props.workingLocation.name }}</h3>
      <span class="portal-copy portal-copy--small">{{ props.workingLocation.address }}</span>
      <span class="portal-copy portal-copy--small">{{ t('booking.byLocationTime') }}: {{ props.workingLocation.timezone }}</span>
    </section>

    <section
      v-if="props.attributionNeedsManualSource"
      class="portal-panel portal-stack portal-stack--tight"
      aria-labelledby="booking-attribution-heading"
    >
      <div class="portal-section-heading">
        <div class="portal-stack portal-stack--tight">
          <h3
            id="booking-attribution-heading"
            class="portal-heading portal-heading--card"
          >
            {{ t('attribution.source') }}
          </h3>
          <p class="portal-copy portal-copy--small">
            {{ t('attribution.description') }}
          </p>
        </div>
      </div>
      <label class="portal-field">
        <span class="portal-label">{{ t('attribution.source') }}</span>
        <select
          class="portal-input portal-select"
          :value="props.attributionSource ?? ''"
          @change="emit('update:attributionSource', ($event.target as HTMLSelectElement).value || null)"
        >
          <option
            value=""
            disabled
          >{{ t('attribution.choose') }}</option>
          <option
            v-for="source in props.attributionSources"
            :key="source"
            :value="source"
          >{{ t('attribution.' + source) }}</option>
        </select>
      </label>
    </section>

    <section
      v-if="props.legalDocuments.length > 0"
      class="portal-panel portal-stack portal-stack--tight"
      aria-labelledby="booking-legal-heading"
    >
      <div class="portal-stack portal-stack--tight">
        <h3
          id="booking-legal-heading"
          class="portal-heading portal-heading--card"
        >
          {{ t('profile.legal') }}
        </h3>
        <p class="portal-copy portal-copy--small">
          {{ t('legal.requiredDescription') }}
        </p>
      </div>
      <LegalConsentChecklist
        :documents="props.legalDocuments"
        :values="props.consentValues"
        :marketing-value="props.marketingConsent"
        :show-marketing="props.showMarketing"
        group-required-acceptance
        :required-acceptance-error="props.requiredAcceptanceError"
        @change="(id, granted) => emit('update:consent', id, granted)"
        @update:marketing-value="(granted) => emit('update:marketingConsent', granted)"
      />
    </section>

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
