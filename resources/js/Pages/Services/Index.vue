<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import ServiceCard from '../../Components/Portal/ServiceCard.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

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
    services: Service[];
    urls: {
        home: string;
        booking: string;
    };
}>();

const { locale, t } = usePortalLocale();
const bookingUrl = props.portal.authenticated ? props.urls.booking : props.urls.home;
</script>

<template>
  <AppShell
    :title="t('services.title')"
    :portal="props.portal"
    :active="props.portal.authenticated ? 'services' : null"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('services.title') }}
          </h1>
          <p class="portal-lede">
            {{ t('services.description') }}
          </p>
        </div>
        <Link
          v-if="props.portal.authenticated"
          :href="props.urls.booking"
          class="portal-button portal-button--primary"
        >
          {{ t('services.book') }}
        </Link>
      </header>

      <div
        v-if="props.services.length"
        class="portal-service-grid portal-service-grid--wide"
      >
        <ServiceCard
          v-for="service in props.services"
          :key="service.id"
          :service="service"
          :locale="locale"
          :booking-url="bookingUrl"
        />
      </div>
      <EmptyState
        v-else
        :title="t('services.empty')"
      />
    </section>
  </AppShell>
</template>
