<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PortalDateTime from '../PortalDateTime.vue';
import { portalText } from '../../locales/portal';
import type { PortalLocale } from '../../types/portal';

type Booking = {
    id: number;
    service: { name: string };
    specialist: { displayName: string };
    startsAt: string;
    endsAt: string;
    localDate: string;
    localTime: string;
    localEndsAt: string;
    timezone: string;
    formatLabel: string;
    format: 'office' | 'home' | 'online';
    statusLabel: string;
    paymentStatusLabel: string;
    location: string | null;
    locationArea: string | null;
    locationSnapshot: { name?: string; address?: string };
    meetingUrl: string | null;
    meetingPending: boolean;
    canReschedule: boolean;
};

const props = defineProps<{
    booking: Booking;
    locale: PortalLocale;
    detailsUrl: string;
}>();

const locationLabel = computed(() => {
    if (props.booking.format === 'online') {
        return null;
    }

    if (props.booking.format === 'home') {
        return props.booking.location ?? props.booking.locationSnapshot.address ?? props.booking.locationArea;
    }

    return props.booking.locationSnapshot.address ?? props.booking.locationSnapshot.name ?? null;
});
</script>

<template>
  <article class="portal-booking-card">
    <div class="portal-booking-card__header">
      <p class="portal-eyebrow">
        {{ portalText(locale, 'home.upcoming') }}
      </p>
      <span class="portal-booking-card__status">{{ booking.statusLabel }}</span>
    </div>
    <div class="portal-booking-card__content">
      <div class="portal-booking-card__date">
        <PortalDateTime
          :value="booking.startsAt"
          :time-zone="booking.timezone"
          :locale="locale"
          mode="date"
        />
        <strong>
          {{ booking.localTime }}–{{ booking.localEndsAt }}
        </strong>
      </div>
      <div class="portal-stack portal-stack--tight min-w-0 wrap-anywhere">
        <h2 class="portal-heading portal-heading--card">
          {{ booking.service.name }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ booking.specialist.displayName }}
        </p>
        <p class="portal-copy portal-copy--small">
          {{ booking.formatLabel }}
        </p>
        <p
          v-if="locationLabel"
          class="portal-copy portal-copy--small"
        >
          {{ locationLabel }}
        </p>
        <a
          v-if="booking.meetingUrl"
          :href="booking.meetingUrl"
          rel="noopener noreferrer"
          target="_blank"
          class="portal-link"
        >
          {{ portalText(locale, 'booking.meeting') }}
        </a>
        <p
          v-else-if="booking.meetingPending"
          class="portal-copy portal-copy--small"
          role="status"
        >
          {{ portalText(locale, 'booking.meetingPending') }}
        </p>
        <p class="portal-copy portal-copy--small">
          {{ booking.paymentStatusLabel }}
        </p>
      </div>
    </div>
    <div class="portal-action-row portal-action-row--inline">
      <Link
        :href="detailsUrl"
        class="portal-button portal-button--primary"
      >
        {{ portalText(locale, 'home.details') }}
      </Link>
    </div>
  </article>
</template>
