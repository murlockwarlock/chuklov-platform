<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCard from '../../Components/Portal/BookingCard.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

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

type HealthAction = { title: string; summary: string; url: string };

const props = defineProps<{
    portal: PortalShell;
    upcomingBooking: Booking | null;
    healthAction: HealthAction | null;
}>();

const { locale, t } = usePortalLocale();
</script>

<template>
  <AppShell
    :title="t('shell.home')"
    :portal="props.portal"
    active="home"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section">
          {{ props.portal.clientName
            ? t('home.greetingWithName', { name: props.portal.clientName })
            : t('home.greeting') }}
        </h1>
      </header>

      <BookingCard
        v-if="props.upcomingBooking"
        :booking="props.upcomingBooking"
        :locale="locale"
        :details-url="props.portal.urls.bookings + '/' + props.upcomingBooking.id"
      />
      <div class="portal-action-row">
        <Link
          :href="props.portal.urls.services"
          class="portal-button portal-button--primary self-start"
          data-testid="home-booking-cta"
        >
          {{ t('home.book') }}
        </Link>
        <Link
          v-if="props.upcomingBooking"
          :href="props.portal.urls.bookings"
          class="portal-link"
        >
          {{ t('bookings.title') }}
        </Link>
      </div>

      <Link
        v-if="props.healthAction"
        :href="props.healthAction.url"
        class="portal-list__row portal-list__row--standalone"
      >
        <span>
          <span class="portal-kicker">{{ t('home.healthAction') }}</span>
          <strong class="portal-list__title">{{ props.healthAction.title }}</strong>
          <span class="portal-list__summary">{{ props.healthAction.summary }}</span>
        </span>
        <span aria-hidden="true">→</span>
      </Link>

      <Link
        :href="props.portal.urls.companion"
        class="portal-list__row portal-list__row--standalone"
      >
        <span>
          <strong class="portal-list__title">{{ t('home.ai') }}</strong>
        </span>
        <span aria-hidden="true">→</span>
      </Link>
    </section>
  </AppShell>
</template>
