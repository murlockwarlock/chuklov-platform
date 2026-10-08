<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Movement = {
    type: string;
    label: string;
    amountMinor: number;
    currency: string;
    occurredAt: string;
};

type Certificate = {
    id: number;
    originalAmountMinor: number;
    balanceMinor: number;
    currency: string;
    status: 'available' | 'spent';
    statusLabel: string;
    purchaserName: string | null;
    issuedAt: string;
    transferUrl: string;
    applyUrls: Record<string, string>;
    history: Movement[];
};

type Obligation = {
    obligationId: number;
    serviceName: string;
    outstandingMinor: number;
    displayCurrency: string;
};

type PageProps = {
    errors?: Record<string, string | string[]>;
    certificates: Certificate[];
    obligations: Obligation[];
    transferUrl: string | null;
    portal: PortalShell;
    urls: {
        home: string;
        finance: string;
    };
};

const props = defineProps<PageProps>();
const page = usePage<PageProps>();
const { t, locale } = usePortalLocale();
const applying = ref<string | null>(null);
const applyAmounts = ref<Record<string, string>>({});
const applyKeys = ref<Record<string, string>>({});
const copied = ref(false);

const transferUrl = computed(() => props.transferUrl);
const errorMessage = computed(() => {
    const errors = page.props.errors ?? {};
    const error = errors.certificate ?? errors.amount ?? errors.currency ?? errors.idempotency_key;

    return Array.isArray(error) ? error[0] ?? null : error ?? null;
});

function formatMoney(minor: number, currency: string): string {
    const digits = currency === 'JPY' ? 0 : 2;

    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / (10 ** digits));
}

function operationKey(certificate: Certificate, obligation: Obligation): string {
    return certificate.id + ':' + obligation.obligationId;
}

function applyKey(certificate: Certificate, obligation: Obligation): string {
    const key = operationKey(certificate, obligation);
    applyKeys.value[key] ??= 'portal-gift-certificate-'
        + (crypto.randomUUID?.() ?? Math.random().toString(36).slice(2));

    return applyKeys.value[key];
}

function defaultAmount(certificate: Certificate, obligation: Obligation): string {
    if (certificate.currency !== obligation.displayCurrency) {
        return '';
    }

    return formatMinor(
        Math.min(certificate.balanceMinor, obligation.outstandingMinor),
        certificate.currency,
    );
}

function formatMinor(minor: number, currency: string): string {
    const digits = currency === 'JPY' ? 0 : 2;

    return (minor / (10 ** digits)).toFixed(digits);
}

function applyCertificate(certificate: Certificate, obligation: Obligation): void {
    const key = operationKey(certificate, obligation);
    if (applying.value !== null) {
        return;
    }

    applying.value = key;
    router.post(certificate.applyUrls[String(obligation.obligationId)], {
        amount: applyAmounts.value[key] ?? defaultAmount(certificate, obligation),
        currency: certificate.currency,
        idempotency_key: applyKey(certificate, obligation),
    }, {
        preserveScroll: true,
        onFinish: () => {
            applying.value = null;
        },
    });
}

function createTransfer(certificate: Certificate): void {
    router.post(certificate.transferUrl, {}, { preserveScroll: true });
}

async function copyTransferUrl(): Promise<void> {
    if (transferUrl.value === null || ! navigator.clipboard) {
        return;
    }

    await navigator.clipboard.writeText(transferUrl.value);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
  <Head :title="t('giftCertificates.title')" />
  <AppShell
    :title="t('giftCertificates.title')"
    :portal="props.portal"
    active="more"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading min-w-0 w-full">
        <div class="portal-stack portal-stack--tight min-w-0">
          <h1 class="portal-heading portal-heading--section">
            {{ t('giftCertificates.title') }}
          </h1>
          <p class="portal-text">
            {{ t('giftCertificates.description') }}
          </p>
        </div>
      </header>

      <div
        v-if="errorMessage"
        class="portal-notice portal-notice--error min-w-0 max-w-full break-words"
        role="alert"
      >
        {{ errorMessage }}
      </div>

      <div
        v-if="transferUrl"
        class="portal-notice min-w-0 max-w-full break-words"
        role="status"
        data-testid="gift-transfer-ready"
      >
        <strong>{{ t('giftCertificates.transferReady') }}</strong>
        <a
          :href="transferUrl"
          class="portal-link block max-w-full break-all"
          data-testid="gift-transfer-link"
        >
          {{ transferUrl }}
        </a>
        <button
          type="button"
          class="portal-button portal-button--secondary mt-3"
          @click="copyTransferUrl"
        >
          {{ copied ? t('giftCertificates.copied') : t('giftCertificates.copy') }}
        </button>
      </div>

      <EmptyState
        v-if="props.certificates.length === 0"
        :title="t('giftCertificates.empty')"
      />

      <div
        v-else
        class="portal-stack portal-stack--loose"
      >
        <article
          v-for="certificate in props.certificates"
          :key="certificate.id"
          class="portal-card portal-stack portal-stack--tight min-w-0"
          data-testid="gift-certificate-card"
        >
          <div class="portal-page-heading min-w-0">
            <div class="min-w-0">
              <h2 class="portal-heading portal-heading--card">
                {{ formatMoney(certificate.balanceMinor, certificate.currency) }}
              </h2>
              <p class="portal-muted">
                {{ t('giftCertificates.nominal') }}: {{ formatMoney(certificate.originalAmountMinor, certificate.currency) }}
              </p>
            </div>
            <span class="portal-badge">{{ certificate.statusLabel }}</span>
          </div>

          <dl class="portal-definition-list">
            <div>
              <dt>{{ t('giftCertificates.currency') }}</dt>
              <dd>{{ certificate.currency }}</dd>
            </div>
            <div>
              <dt>{{ t('giftCertificates.issuedAt') }}</dt>
              <dd>{{ certificate.issuedAt }}</dd>
            </div>
            <div v-if="certificate.purchaserName">
              <dt>{{ t('giftCertificates.from') }}</dt>
              <dd>{{ certificate.purchaserName }}</dd>
            </div>
          </dl>

          <button
            v-if="certificate.status === 'available'"
            type="button"
            class="portal-button portal-button--secondary"
            @click="createTransfer(certificate)"
          >
            {{ t('giftCertificates.gift') }}
          </button>

          <div
            v-if="certificate.status === 'available' && props.obligations.length"
            class="portal-stack portal-stack--tight"
          >
            <h3 class="portal-heading portal-heading--small">
              {{ t('giftCertificates.applyTitle') }}
            </h3>
            <div
              v-for="obligation in props.obligations"
              :key="obligation.obligationId"
              class="portal-card portal-card--muted portal-stack portal-stack--tight min-w-0"
            >
              <div class="portal-page-heading min-w-0">
                <span class="min-w-0 break-words">{{ obligation.serviceName }}</span>
                <strong>{{ formatMoney(obligation.outstandingMinor, obligation.displayCurrency) }}</strong>
              </div>
              <div
                v-if="certificate.applyUrls[String(obligation.obligationId)]"
                class="portal-inline-form"
              >
                <label class="portal-field min-w-0">
                  <span class="portal-field__label">{{ t('giftCertificates.amount') }}</span>
                  <input
                    v-model="applyAmounts[operationKey(certificate, obligation)]"
                    class="portal-input"
                    inputmode="decimal"
                    :placeholder="defaultAmount(certificate, obligation)"
                  >
                </label>
                <button
                  type="button"
                  class="portal-button portal-button--primary"
                  :disabled="applying !== null"
                  @click="applyCertificate(certificate, obligation)"
                >
                  {{ applying === operationKey(certificate, obligation) ? t('giftCertificates.applying') : t('giftCertificates.apply') }}
                </button>
              </div>
            </div>
          </div>

          <details>
            <summary class="portal-link cursor-pointer">
              {{ t('giftCertificates.history') }}
            </summary>
            <ul class="portal-list portal-list--compact mt-3">
              <li
                v-for="movement in certificate.history"
                :key="movement.type + '-' + movement.occurredAt + '-' + movement.amountMinor"
                class="portal-list__row"
              >
                <span class="min-w-0">
                  <strong class="portal-list__title">{{ movement.label }}</strong>
                  <small class="portal-muted block">{{ movement.occurredAt }}</small>
                </span>
                <span v-if="movement.amountMinor">{{ formatMoney(movement.amountMinor, movement.currency) }}</span>
              </li>
            </ul>
          </details>
        </article>
      </div>
    </section>
  </AppShell>
</template>
