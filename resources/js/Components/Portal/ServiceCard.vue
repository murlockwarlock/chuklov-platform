<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { portalText } from '../../locales/portal';
import type { PortalLocale } from '../../types/portal';
import { formatMajorPrice } from '../../utils/formatMajorPrice';

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

const props = defineProps<{
    service: Service;
    locale: PortalLocale;
    bookingUrl: string;
    authenticated: boolean;
    homeUrl: string;
}>();

const duration = computed(() => {
    if (props.service.durationMinutes === null) {
        return null;
    }

    return portalText(props.locale, 'service.durationMinutes', { value: props.service.durationMinutes });
});

const price = computed(() => {
    if (props.service.priceMajor === null || props.service.priceCurrency === null) {
        return portalText(props.locale, 'service.priceUnavailable');
    }

    const formatted = formatMajorPrice(props.service.priceMajor, props.service.priceCurrency, props.locale);

    return portalText(props.locale, 'service.from') + ' ' + formatted;
});

const bookingLink = computed(() => {
    const separator = props.bookingUrl.includes('?') ? '&' : '?';

    return `${props.bookingUrl}${separator}service_id=${props.service.id}`;
});

const purchaseIdempotencyKey = `portal-purchase-${props.service.id}-${typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2)}`;
</script>

<template>
  <a
    v-if="service.catalogType === 'service'"
    class="portal-service-card"
    :class="{ 'portal-service-card--with-image': service.imageUrl }"
    :href="bookingLink"
    :aria-label="service.name"
  >
    <div
      v-if="service.imageUrl"
      class="portal-service-card__media"
    >
      <img
        :src="service.imageUrl"
        :alt="service.name"
        class="portal-service-card__image"
        loading="lazy"
      >
    </div>
    <div class="portal-service-card__body">
      <h3 class="portal-heading portal-heading--card">
        {{ service.name }}
      </h3>
      <div class="portal-service-card__meta">
        <span v-if="duration">{{ duration }}</span>
        <span>{{ price }}</span>
      </div>
    </div>
    <span
      class="portal-link portal-service-card__link"
      aria-hidden="true"
    >
      {{ portalText(locale, 'services.book') }}
      <span>→</span>
    </span>
  </a>
  <article
    v-else
    class="portal-service-card"
    :class="{ 'portal-service-card--with-image': service.imageUrl }"
  >
    <div
      v-if="service.imageUrl"
      class="portal-service-card__media"
    >
      <img
        :src="service.imageUrl"
        :alt="service.name"
        class="portal-service-card__image"
        loading="lazy"
      >
    </div>
    <div class="portal-service-card__body">
      <h3 class="portal-heading portal-heading--card">
        {{ service.name }}
      </h3>
      <div class="portal-service-card__meta">
        <span v-if="duration">{{ duration }}</span>
        <span>{{ price }}</span>
      </div>
    </div>
    <Link
      v-if="authenticated && service.purchaseUrl"
      :href="service.purchaseUrl"
      method="post"
      as="button"
      class="portal-link portal-service-card__link"
      :data="{ idempotency_key: purchaseIdempotencyKey }"
    >
      {{ portalText(locale, 'services.buy') }}
      <span aria-hidden="true">→</span>
    </Link>
    <a
      v-else
      :href="homeUrl"
      class="portal-link portal-service-card__link"
    >
      {{ portalText(locale, 'services.signInToBuy') }}
      <span aria-hidden="true">→</span>
    </a>
  </article>
</template>
