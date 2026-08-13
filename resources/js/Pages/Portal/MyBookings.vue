<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PortalDateTime from '../../Components/PortalDateTime.vue';

type Booking = {
    id: number;
    service: { name: string };
    specialist: { displayName: string };
    startsAt: string;
    localDate: string;
    localTime: string;
    timezone: string;
    formatLabel: string;
    status: string;
    statusLabel: string;
    pendingReview: boolean;
};

const props = defineProps<{
    upcoming: Booking[];
    history: Booking[];
    urls: { create: string; services: string };
}>();
</script>

<template>
  <Head title="My bookings" />
  <main class="portal-page">
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Client portal
          </p>
          <h1 class="portal-heading portal-heading--page">
            My bookings
          </h1>
          <p class="portal-lede">
            Your appointments and requests, shown in the timezone saved for each booking.
          </p>
        </div>
      </header>

      <section
        class="portal-stack"
        aria-labelledby="upcoming-heading"
      >
        <h2
          id="upcoming-heading"
          class="portal-heading portal-heading--section"
        >
          Upcoming
        </h2>
        <p
          v-if="props.upcoming.length === 0"
          class="portal-empty"
        >
          You have no upcoming bookings.
        </p>
        <div
          v-else
          class="portal-grid portal-grid--cards"
        >
          <Link
            v-for="booking in props.upcoming"
            :key="booking.id"
            :href="`/portal/bookings/${booking.id}`"
            class="portal-card portal-card--interactive portal-stack portal-stack--tight"
          >
            <span class="portal-kicker">{{ booking.statusLabel }}</span>
            <span class="portal-heading portal-heading--card">{{ booking.service.name }}</span>
            <span class="portal-card__summary">{{ booking.specialist.displayName }} · {{ booking.formatLabel }}</span>
            <span class="portal-card__summary">
              <PortalDateTime
                :value="booking.startsAt"
                :time-zone="booking.timezone"
              />
            </span>
            <span class="portal-card__summary">{{ booking.timezone }}</span>
          </Link>
        </div>
      </section>

      <section
        class="portal-stack"
        aria-labelledby="history-heading"
      >
        <h2
          id="history-heading"
          class="portal-heading portal-heading--section"
        >
          History
        </h2>
        <p
          v-if="props.history.length === 0"
          class="portal-empty"
        >
          Your completed booking history will appear here.
        </p>
        <div
          v-else
          class="portal-grid portal-grid--cards"
        >
          <Link
            v-for="booking in props.history"
            :key="booking.id"
            :href="`/portal/bookings/${booking.id}`"
            class="portal-card portal-card--interactive portal-stack portal-stack--tight"
          >
            <span class="portal-kicker">{{ booking.statusLabel }}</span>
            <span class="portal-heading portal-heading--card">{{ booking.service.name }}</span>
            <span class="portal-card__summary">{{ booking.specialist.displayName }} · {{ booking.formatLabel }}</span>
            <span class="portal-card__summary">{{ booking.localDate }} {{ booking.localTime }} · {{ booking.timezone }}</span>
          </Link>
        </div>
      </section>

      <div class="portal-cluster">
        <Link
          :href="props.urls.create"
          class="portal-button portal-button--primary"
        >
          Book a visit
        </Link>
        <Link
          :href="props.urls.services"
          class="portal-button portal-button--secondary"
        >
          Back to services
        </Link>
      </div>
    </section>
  </main>
</template>
