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
    priceMajor: string | null;
    priceCurrency: string | null;
};

const props = defineProps<{
    portal: PortalShell;
    upcomingBooking: Booking | null;
    services: Service[];
    attribution: { needsManualSource: boolean };
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

      <section class="portal-grid portal-grid--cards">
        <Link
          :href="props.portal.urls.b2b"
          class="portal-card portal-card--interactive portal-stack portal-stack--tight"
        >
          <strong class="portal-heading portal-heading--section">
            {{ t('b2b.cta') }}
          </strong>
          <span class="portal-card__summary">
            {{ t('b2b.ctaDescription') }}
          </span>
        </Link>
        <Link
          :href="props.portal.urls.referrals"
          class="portal-card portal-card--interactive portal-stack portal-stack--tight"
        >
          <strong class="portal-heading portal-heading--section">
            {{ t('home.referrals') }}
          </strong>
          <span class="portal-card__summary">
            {{ t('home.referralsDescription') }}
          </span>
        </Link>
        <Link
          :href="props.portal.urls.feedback"
          class="portal-card portal-card--interactive portal-stack portal-stack--tight"
        >
          <strong class="portal-heading portal-heading--section">
            {{ t('home.feedback') }}
          </strong>
          <span class="portal-card__summary">
            {{ t('home.feedbackDescription') }}
          </span>
        </Link>
        <Link
          v-if="props.attribution.needsManualSource"
          :href="props.portal.urls.attribution"
          class="portal-card portal-card--interactive portal-stack portal-stack--tight"
        >
          <strong class="portal-heading portal-heading--section">
            {{ t('home.sourceQuestion') }}
          </strong>
          <span class="portal-card__summary">
            {{ t('home.sourceQuestionDescription') }}
          </span>
        </Link>
      </section>
    </section>
  </AppShell>
</template>
