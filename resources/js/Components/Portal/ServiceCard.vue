<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { portalText } from '../../locales/portal';
import type { PortalLocale } from '../../types/portal';
import { formatMajorPrice } from '../../utils/formatMajorPrice';
import { formatServiceText } from '../../utils/formatServiceText';

type Service = {
    id: number;
    catalogType: 'service' | 'physical_product' | 'online_product' | 'gift_certificate';
    name: string;
    summary: string | null;
    description: string | null;
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

const summaryText = computed(() => props.service.summary === null ? null : formatServiceText(props.service.summary));

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

const fullDescription = computed(() => {
    const description = props.service.description;

    if (description === null || description.trim() === '') {
        return null;
    }

    if (props.service.summary?.trim() === description.trim()) {
        return null;
    }

    return description;
});

const fullDescriptionText = computed(() => fullDescription.value === null ? null : formatServiceText(fullDescription.value));

const bookingLink = computed(() => {
    const separator = props.bookingUrl.includes('?') ? '&' : '?';

    return `${props.bookingUrl}${separator}service_id=${props.service.id}`;
});

const purchaseIdempotencyKey = `portal-purchase-${props.service.id}-${typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2)}`;
</script>

<template>
  <article
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
      <p
        v-if="summaryText"
        class="portal-card__summary portal-service-card__summary break-words"
      >
        {{ summaryText }}
      </p>
      <details
        v-if="fullDescription"
        class="portal-service-card__details"
      >
        <summary class="portal-service-card__details-toggle">
          {{ portalText(locale, 'services.fullDescription') }}
        </summary>
        <p class="portal-service-card__description break-words">
          {{ fullDescriptionText }}
        </p>
      </details>
      <div class="portal-service-card__meta">
        <span v-if="duration">{{ duration }}</span>
        <span>{{ price }}</span>
      </div>
    </div>
    <a
      v-if="service.catalogType === 'service'"
      class="portal-button portal-button--primary portal-service-card__link"
      :href="bookingLink"
    >
      {{ portalText(locale, 'services.book') }}
    </a>
    <Link
      v-else-if="authenticated && service.purchaseUrl"
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
