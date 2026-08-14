<script setup lang="ts">
import { computed } from 'vue';
import { portalText } from '../../locales/portal';
import type { PortalLocale } from '../../types/portal';

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
    service: Service;
    locale: PortalLocale;
    bookingUrl: string;
}>();

const duration = computed(() => {
    if (props.service.durationMinutes === null) {
        return null;
    }

    return portalText(props.locale, 'service.durationMinutes', { value: props.service.durationMinutes });
});

const price = computed(() => {
    if (props.service.priceMinor === null || props.service.priceCurrency === null) {
        return portalText(props.locale, 'service.priceUnavailable');
    }

    const formatted = new Intl.NumberFormat(props.locale === 'ru' ? 'ru-RU' : 'en-GB', {
        style: 'currency',
        currency: props.service.priceCurrency,
        maximumFractionDigits: 0,
    }).format(props.service.priceMinor / 100);

    return portalText(props.locale, 'service.from') + ' ' + formatted;
});

const bookingLink = computed(() => {
    const separator = props.bookingUrl.includes('?') ? '&' : '?';

    return `${props.bookingUrl}${separator}service_id=${props.service.id}`;
});
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
        v-if="service.summary"
        class="portal-card__summary"
      >
        {{ service.summary }}
      </p>
      <div class="portal-service-card__meta">
        <span v-if="duration">{{ duration }}</span>
        <span>{{ price }}</span>
      </div>
    </div>
    <a
      :href="bookingLink"
      class="portal-link portal-service-card__link"
    >
      {{ portalText(locale, 'services.book') }}
      <span aria-hidden="true">→</span>
    </a>
  </article>
</template>
