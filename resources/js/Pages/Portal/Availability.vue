<script setup lang="ts">
import PortalDateTime from '../../Components/PortalDateTime.vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalPageHeader from '../../Components/Portal/PortalPageHeader.vue';
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

const { t, locale, formatDate: formatPortalDate } = usePortalLocale();

function formatDate(date: string): string {
    return formatPortalDate(date);
}
</script>

<template>
  <AppShell
    :title="t('booking.chooseTime')"
    :portal="props.portal"
    active="bookings"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <PortalPageHeader
        :title="t('booking.chooseTime')"
        :eyebrow="t('booking.' + props.query.format)"
        :description="formatDate(props.query.dateFrom) + ' — ' + formatDate(props.query.dateTo)"
        :back-href="props.portal.urls.services"
        :back-label="t('common.back')"
      />

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
    </section>
  </AppShell>
</template>
