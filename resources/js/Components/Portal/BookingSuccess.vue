<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortalDateTime from '../PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalLocale } from '../../types/portal';

const props = defineProps<{
    message: string;
    serviceName: string | null;
    specialistName: string | null;
    selectedStart: string | null;
    timezone: string;
    locale: PortalLocale;
    formatLabel: string;
    urls: { bookings: string; services: string; referrals: string };
}>();

const { t } = usePortalLocale();
</script>

<template>
  <section
    class="portal-booking-success portal-stack"
    aria-labelledby="booking-success-heading"
  >
    <header class="portal-booking-success__header portal-stack portal-stack--tight">
      <p class="portal-eyebrow">
        CHUKLOV
      </p>
      <h2
        id="booking-success-heading"
        aria-live="polite"
        class="portal-heading portal-heading--section"
      >
        {{ props.message }}
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
      <div v-if="props.selectedStart">
        <dt>{{ t('booking.dateTime') }}</dt>
        <dd>
          <PortalDateTime
            :value="props.selectedStart"
            :time-zone="props.timezone"
            :locale="props.locale"
            mode="date"
          />
          <span> · </span>
          <PortalDateTime
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

    <div class="portal-booking-success__actions">
      <Link
        :href="props.urls.bookings"
        class="portal-button portal-button--primary"
      >
        {{ t('bookings.title') }}
      </Link>
      <Link
        :href="props.urls.services"
        class="portal-button portal-button--secondary"
      >
        {{ t('booking.bookAgain') }}
      </Link>
      <Link
        :href="props.urls.referrals"
        class="portal-button portal-button--secondary"
        data-testid="booking-referrals-cta"
      >
        {{ t('home.referrals') }}
      </Link>
    </div>
  </section>
</template>
