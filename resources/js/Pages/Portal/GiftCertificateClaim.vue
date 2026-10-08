<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Certificate = {
    originalAmountMinor: number;
    balanceMinor: number;
    currency: string;
    purchaserName: string | null;
};

type PageProps = {
    token: string | null;
    authenticated: boolean;
    certificate: Certificate | null;
    portal: PortalShell;
    urls: {
        home: string;
        preview: string;
        claim: string;
    };
    errors?: Record<string, string | string[]>;
};

const props = defineProps<PageProps>();
const page = usePage<PageProps>();
const { t, locale } = usePortalLocale();
const loading = ref(true);

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
    if (props.token === null) {
        return;
    }

    router.post(props.urls.claim, { token: props.token }, { preserveScroll: true });
}

function fragmentToken(): string | null {
    const match = window.location.hash.match(/^#token=([a-f0-9]{64})$/);

    return match?.[1] ?? null;
}

onMounted(() => {
    const token = fragmentToken();
    if (token === null) {
        loading.value = false;

        return;
    }

    router.post(props.urls.preview, { token }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value = false;
        },
    });
});
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
        <p
          v-if="loading"
          class="portal-text"
        >
          {{ t('giftCertificates.loading') }}
        </p>
        <template v-else-if="props.certificate">
          <p class="portal-text">
            {{ t('giftCertificates.claimDescription', { amount: formatMoney(props.certificate.balanceMinor, props.certificate.currency) }) }}
          </p>
          <p class="portal-muted">
            {{ t('giftCertificates.nominal') }}: {{ formatMoney(props.certificate.originalAmountMinor, props.certificate.currency) }}
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
        </template>
        <div
          v-else
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ t('giftCertificates.invalidLink') }}
        </div>
      </div>
    </section>
  </AppShell>
</template>
