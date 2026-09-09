<script setup lang="ts">
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
    priceMajor: string | null;
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
    active="bookings"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--tight">
      <header class="portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section">
          {{ t('services.title') }}
        </h1>
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
