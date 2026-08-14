<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
    statusLabel: string;
    canReschedule: boolean;
};

defineProps<{
    booking: Booking;
    locale: PortalLocale;
    detailsUrl: string;
}>();
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
      <div class="portal-stack portal-stack--tight">
        <h2 class="portal-heading portal-heading--card">
          {{ booking.service.name }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ booking.specialist.displayName }}
        </p>
        <p class="portal-copy portal-copy--small">
          {{ booking.formatLabel }}
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
