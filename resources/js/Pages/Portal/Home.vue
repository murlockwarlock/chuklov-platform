<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCard from '../../Components/Portal/BookingCard.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import ServiceCard from '../../Components/Portal/ServiceCard.vue';
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

type Service = {
    id: number;
    name: string;
    summary: string | null;
    imageUrl: string | null;
    durationMinutes: number | null;
    priceMinor: number | null;
    priceCurrency: string | null;
};

const props = defineProps<{
    portal: PortalShell;
    upcomingBooking: Booking | null;
    services: Service[];
}>();

const { locale, t } = usePortalLocale();
</script>

<template>
  <AppShell
    :title="t('shell.home')"
    :portal="props.portal"
    active="home"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ props.portal.clientName
              ? t('home.greetingWithName', { name: props.portal.clientName })
              : t('home.greeting') }}
          </h1>
        </div>
        <Link
          :href="props.portal.urls.booking"
          class="portal-button portal-button--primary"
        >
          {{ t('home.book') }}
        </Link>
      </header>

      <BookingCard
        v-if="props.upcomingBooking"
        :booking="props.upcomingBooking"
        :locale="locale"
        :details-url="props.portal.urls.bookings + '/' + props.upcomingBooking.id"
      />
      <EmptyState
        v-else
        :title="t('home.noUpcoming')"
        :description="t('home.noUpcomingDescription')"
      >
        <Link
          :href="props.portal.urls.booking"
          class="portal-button portal-button--primary"
        >
          {{ t('home.book') }}
        </Link>
      </EmptyState>

      <section class="portal-stack">
        <header class="portal-section-heading">
          <h2 class="portal-heading portal-heading--section">
            {{ t('home.services') }}
          </h2>
          <Link
            :href="props.portal.urls.services"
            class="portal-link"
          >
            {{ t('home.allServices') }}
          </Link>
        </header>

        <div
          v-if="props.services.length"
          class="portal-service-grid"
        >
          <ServiceCard
            v-for="service in props.services.slice(0, 3)"
            :key="service.id"
            :service="service"
            :locale="locale"
            :booking-url="props.portal.urls.booking"
          />
        </div>
        <p
          v-else
          class="portal-copy"
        >
          {{ t('home.noServices') }}
        </p>
      </section>
    </section>
  </AppShell>
</template>
