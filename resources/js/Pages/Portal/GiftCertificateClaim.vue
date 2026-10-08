<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type PageProps = {
    token: string;
    authenticated: boolean;
    certificate: {
        originalAmountMinor: number;
        currency: string;
        purchaserName: string | null;
    };
    portal: PortalShell;
    urls: {
        home: string;
        claim: string;
    };
    errors?: Record<string, string | string[]>;
};

const props = defineProps<PageProps>();
const page = usePage<PageProps>();
const { t, locale } = usePortalLocale();

function formatMoney(minor: number, currency: string): string {
    const digits = currency === 'JPY' ? 0 : 2;

    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / (10 ** digits));
}

function claim(): void {
    router.post(props.urls.claim, {}, { preserveScroll: true });
}
</script>

<template>
  <Head :title="t('giftCertificates.claimTitle')" />
  <AppShell
    :title="t('giftCertificates.claimTitle')"
    :portal="props.portal"
    active="more"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <div class="portal-card portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section">
          {{ t('giftCertificates.claimTitle') }}
        </h1>
        <p class="portal-text">
          {{ t('giftCertificates.claimDescription', { amount: formatMoney(props.certificate.originalAmountMinor, props.certificate.currency) }) }}
        </p>
        <p
          v-if="props.certificate.purchaserName"
          class="portal-muted"
        >
          {{ t('giftCertificates.from') }}: {{ props.certificate.purchaserName }}
        </p>
        <div
          v-if="page.props.errors?.token"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ Array.isArray(page.props.errors.token) ? page.props.errors.token[0] : page.props.errors.token }}
        </div>
        <button
          v-if="props.authenticated"
          type="button"
          class="portal-button portal-button--primary"
          @click="claim"
        >
          {{ t('giftCertificates.receive') }}
        </button>
        <a
          v-else
          :href="props.urls.home"
          class="portal-button portal-button--primary text-center"
        >
          {{ t('giftCertificates.signInToReceive') }}
        </a>
      </div>
    </section>
  </AppShell>
</template>
