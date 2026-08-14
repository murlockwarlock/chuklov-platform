<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import PortalDateTime from '../../Components/PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Booking = {
    id: number;
    service: { name: string };
    specialist: { displayName: string };
    startsAt: string;
    timezone: string;
    formatLabel: string;
    statusLabel: string;
};

const props = defineProps<{
    portal: PortalShell;
    upcoming: Booking[];
    history: Booking[];
    urls: { create: string; services: string };
}>();

const { locale, t } = usePortalLocale();
</script>

<template>
  <AppShell
    :title="t('bookings.title')"
    :portal="props.portal"
    active="bookings"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('bookings.title') }}
          </h1>
        </div>
        <Link
          :href="props.urls.create"
          class="portal-button portal-button--primary"
        >
          {{ t('bookings.new') }}
        </Link>
      </header>

      <section class="portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('bookings.upcoming') }}
        </h2>
        <EmptyState
          v-if="props.upcoming.length === 0"
          :title="t('bookings.empty')"
        >
          <Link
            :href="props.urls.create"
            class="portal-button portal-button--primary"
          >
            {{ t('bookings.book') }}
          </Link>
        </EmptyState>
        <div
          v-else
          class="portal-grid portal-grid--cards"
        >
          <Link
            v-for="booking in props.upcoming"
            :key="booking.id"
            :href="props.portal.urls.bookings + '/' + booking.id"
            class="portal-card portal-card--interactive portal-stack portal-stack--tight"
          >
            <span class="portal-kicker">{{ booking.statusLabel }}</span>
            <span class="portal-heading portal-heading--card">{{ booking.service.name }}</span>
            <span class="portal-card__summary">{{ booking.specialist.displayName }} · {{ booking.formatLabel }}</span>
            <span class="portal-card__summary">
              <PortalDateTime
                :value="booking.startsAt"
                :time-zone="booking.timezone"
                :locale="locale"
              />
            </span>
          </Link>
        </div>
      </section>

      <section class="portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('bookings.history') }}
        </h2>
        <EmptyState
          v-if="props.history.length === 0"
          :title="t('bookings.emptyHistory')"
        />
        <div
          v-else
          class="portal-grid portal-grid--cards"
        >
          <Link
            v-for="booking in props.history"
            :key="booking.id"
            :href="props.portal.urls.bookings + '/' + booking.id"
            class="portal-card portal-card--interactive portal-stack portal-stack--tight"
          >
            <span class="portal-kicker">{{ booking.statusLabel }}</span>
            <span class="portal-heading portal-heading--card">{{ booking.service.name }}</span>
            <span class="portal-card__summary">{{ booking.specialist.displayName }} · {{ booking.formatLabel }}</span>
            <span class="portal-card__summary">
              <PortalDateTime
                :value="booking.startsAt"
                :time-zone="booking.timezone"
                :locale="locale"
              />
            </span>
          </Link>
        </div>
      </section>
    </section>
  </AppShell>
</template>
