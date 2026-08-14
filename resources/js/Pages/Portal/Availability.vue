<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortalDateTime from '../../Components/PortalDateTime.vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayTimezone: string;
    format: 'office' | 'home' | 'online';
};

type Availability = {
    specialistId: number;
    serviceId: number;
    displayTimezone: string;
    slots: AvailabilitySlot[];
};

type AvailabilityQuery = {
    specialistId: number;
    serviceId: number;
    dateFrom: string;
    dateTo: string;
    format: 'office' | 'home' | 'online';
};

const props = defineProps<{
    portal: PortalShell;
    availability: Availability;
    query: AvailabilityQuery;
}>();

const { locale, t } = usePortalLocale();

function formatDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat(locale.value === 'ru' ? 'ru-RU' : 'en-GB', {
        day: 'numeric',
        month: 'long',
    }).format(new Date(year, month - 1, day));
}
</script>

<template>
  <AppShell
    :title="t('booking.chooseTime')"
    :portal="props.portal"
    active="services"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ t('booking.' + props.query.format) }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            {{ t('booking.chooseTime') }}
          </h1>
          <p class="portal-lede">
            {{ formatDate(props.query.dateFrom) }} — {{ formatDate(props.query.dateTo) }}
          </p>
        </div>
      </header>

      <p
        v-if="props.availability.slots.length === 0"
        class="portal-notice"
        role="status"
      >
        {{ t('booking.noSlots') }}
      </p>

      <div
        v-else
        class="portal-grid portal-grid--cards"
        :aria-label="t('booking.chooseTime')"
      >
        <article
          v-for="slot in props.availability.slots"
          :key="slot.startsAt"
          class="portal-panel portal-stack portal-stack--tight"
        >
          <p class="portal-heading portal-heading--section">
            <PortalDateTime
              :value="slot.startsAt"
              :time-zone="props.availability.displayTimezone"
              :locale="locale"
            />
          </p>
          <p class="portal-copy portal-copy--small">
            {{ t('booking.until') }}
            <PortalDateTime
              :value="slot.endsAt"
              :time-zone="props.availability.displayTimezone"
              :locale="locale"
            />
          </p>
        </article>
      </div>

      <Link
        :href="props.portal.urls.home"
        class="portal-button portal-button--secondary self-start"
      >
        {{ t('shell.home') }}
      </Link>
    </section>
  </AppShell>
</template>
