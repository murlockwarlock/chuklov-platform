<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import ServiceCard from '../../Components/Portal/ServiceCard.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Service = {
    id: number;
    catalogType: 'service' | 'physical_product' | 'online_product';
    name: string;
    summary: string | null;
    imageUrl: string | null;
    durationMinutes: number | null;
    priceMajor: string | null;
    priceCurrency: string | null;
    purchaseUrl: string | null;
};

type PortalPageProps = {
    errors?: Record<string, string | string[]>;
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
const page = usePage<PortalPageProps>();
const paymentError = computed(() => {
    const error = page.props.errors?.payment;

    return Array.isArray(error) ? error[0] ?? null : error ?? null;
});
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
        v-if="paymentError"
        class="portal-notice portal-notice--error min-w-0 max-w-full break-words"
        role="alert"
      >
        {{ paymentError }}
      </div>

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
          :authenticated="props.portal.authenticated"
          :home-url="props.urls.home"
        />
      </div>
      <EmptyState
        v-else
        :title="t('services.empty')"
      />
    </section>
  </AppShell>
</template>
