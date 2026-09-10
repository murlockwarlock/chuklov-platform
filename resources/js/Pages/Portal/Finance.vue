<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type FinanceHistory = {
    available: boolean;
    amountMinor: number | null;
    currency: string | null;
    occurredAt: string;
    methodLabel: string;
    receiptUrl: string | null;
};

type Obligation = {
    available: boolean;
    serviceName: string;
    bookingUrl: string | null;
    completedAt: string | null;
    obligationMinor: number | null;
    paidMinor: number | null;
    outstandingMinor: number | null;
    displayCurrency: string | null;
    originalCurrency: string | null;
    status: 'outstanding' | 'partially_paid' | 'settled' | 'unavailable';
    statusLabel: string;
    history: FinanceHistory[];
    demoPayment: DemoPayment | null;
};

type DemoPayment = {
    stateLabel: string;
    canStart: boolean;
    canSucceed: boolean;
    canFail: boolean;
    canRefund: boolean;
    startUrl: string;
    successUrl: string | null;
    failUrl: string | null;
    refundUrl: string | null;
};

type Total = { amountMinor: number; currency: string };

const props = defineProps<{
    portal: PortalShell;
    obligations: Obligation[];
    totals: Total[];
    hasUnavailableObligations: boolean;
    urls: { home: string; bookings: string };
}>();

const { t, locale } = usePortalLocale();

function demoKey(): string {
    const value = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : Math.random().toString(36).slice(2);

    return `portal-demo-${value}`;
}

function formatMoney(minor: number, currency: string): string {
    const digits = currency === 'JPY' ? 0 : 2;
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / (10 ** digits));
}

function formatNullableMoney(minor: number | null, currency: string | null): string {
    return minor === null || currency === null
        ? t('finance.entryUnavailable')
        : formatMoney(minor, currency);
}
</script>

<template>
  <Head :title="t('finance.title')" />
  <AppShell
    :title="t('finance.title')"
    :portal="props.portal"
    active="more"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <h1 class="portal-heading portal-heading--section">
            {{ t('finance.title') }}
          </h1>
        </div>
        <Link
          :href="props.urls.bookings"
          class="portal-link"
        >
          {{ t('finance.backBookings') }}
        </Link>
      </header>

      <div
        v-if="props.hasUnavailableObligations"
        class="portal-notice"
        role="status"
      >
        {{ t('finance.partialUnavailable') }}
      </div>

      <div
        v-if="props.obligations.some((obligation) => obligation.demoPayment !== null)"
        class="portal-notice"
        role="status"
      >
        <strong class="block">{{ t('finance.demoTitle') }}</strong>
        <span>{{ t('finance.demoDescription') }}</span>
      </div>

      <section
        v-if="props.totals.length"
        class="portal-content-section portal-stack portal-stack--tight"
        aria-labelledby="finance-total-heading"
      >
        <h2
          id="finance-total-heading"
          class="portal-heading portal-heading--card"
        >
          {{ t('finance.totalOutstanding') }}
        </h2>
        <dl class="portal-finance-rows">
          <div
            v-for="total in props.totals"
            :key="total.currency"
            class="portal-finance-row"
          >
            <dt>{{ total.currency }}</dt>
            <dd>{{ formatMoney(total.amountMinor, total.currency) }}</dd>
          </div>
        </dl>
      </section>

      <EmptyState
        v-if="!props.obligations.length"
        :title="t('finance.empty')"
        :description="t('finance.emptyDescription')"
      >
        <Link
          :href="props.urls.home"
          class="portal-button portal-button--primary"
        >
          {{ t('common.back') }}
        </Link>
      </EmptyState>

      <section
        v-for="obligation in props.obligations"
        :key="obligation.bookingUrl ?? obligation.serviceName + obligation.completedAt"
        class="portal-content-section portal-stack portal-stack--tight"
      >
        <header class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <h2 class="portal-heading portal-heading--section break-words">
              {{ obligation.serviceName }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ obligation.completedAt ?? t('finance.visitDateUnavailable') }}
              <span v-if="obligation.originalCurrency && obligation.displayCurrency && obligation.originalCurrency !== obligation.displayCurrency">
                · {{ obligation.originalCurrency }}
              </span>
            </p>
          </div>
          <span
            class="inline-flex max-w-full shrink-0 rounded-full bg-[var(--portal-color-surface-muted)] px-3 py-1 text-xs font-semibold text-[var(--portal-color-ink-soft)]"
          >
            {{ obligation.statusLabel }}
          </span>
        </header>

        <div
          v-if="!obligation.available"
          class="portal-notice"
          role="status"
        >
          {{ t('finance.obligationUnavailable') }}
        </div>

        <dl
          v-else
          class="portal-finance-rows"
        >
          <div class="portal-finance-row">
            <dt>{{ t('finance.obligation') }}</dt>
            <dd>{{ formatNullableMoney(obligation.obligationMinor, obligation.displayCurrency) }}</dd>
          </div>
          <div class="portal-finance-row">
            <dt>{{ t('finance.paid') }}</dt>
            <dd>{{ formatNullableMoney(obligation.paidMinor, obligation.displayCurrency) }}</dd>
          </div>
          <div class="portal-finance-row">
            <dt>{{ t('finance.remaining') }}</dt>
            <dd>{{ formatNullableMoney(obligation.outstandingMinor, obligation.displayCurrency) }}</dd>
          </div>
        </dl>

        <section
          v-if="obligation.demoPayment"
          class="portal-stack portal-stack--tight rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)] bg-[var(--portal-color-surface-muted)] p-4"
          :aria-label="t('finance.demoTitle')"
        >
          <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
            <h3 class="min-w-0 break-words font-semibold text-[var(--portal-color-ink)]">
              {{ t('finance.demoTitle') }}
            </h3>
            <span class="break-words text-sm text-[var(--portal-color-ink-soft)]">
              {{ obligation.demoPayment.stateLabel }}
            </span>
          </div>
          <div class="flex min-w-0 flex-wrap gap-2">
            <Link
              v-if="obligation.demoPayment.canStart"
              :href="obligation.demoPayment.startUrl"
              method="post"
              as="button"
              class="portal-button portal-button--primary"
              :data="{ idempotency_key: demoKey() }"
            >
              {{ t('finance.demoStart') }}
            </Link>
            <Link
              v-if="obligation.demoPayment.canSucceed && obligation.demoPayment.successUrl"
              :href="obligation.demoPayment.successUrl"
              method="post"
              as="button"
              class="portal-button portal-button--secondary"
            >
              {{ t('finance.demoSuccess') }}
            </Link>
            <Link
              v-if="obligation.demoPayment.canFail && obligation.demoPayment.failUrl"
              :href="obligation.demoPayment.failUrl"
              method="post"
              as="button"
              class="portal-button portal-button--secondary"
            >
              {{ t('finance.demoFail') }}
            </Link>
            <Link
              v-if="obligation.demoPayment.canRefund && obligation.demoPayment.refundUrl"
              :href="obligation.demoPayment.refundUrl"
              method="post"
              as="button"
              class="portal-button portal-button--secondary"
            >
              {{ t('finance.demoRefund') }}
            </Link>
          </div>
        </section>

        <div
          v-if="obligation.history.length"
          class="min-w-0 overflow-hidden rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)]"
        >
          <div class="border-b border-[var(--portal-color-border)] px-4 py-3">
            <h3 class="font-semibold text-[var(--portal-color-ink)]">
              {{ t('finance.history') }}
            </h3>
          </div>
          <ul class="divide-y divide-[var(--portal-color-border)]">
            <li
              v-for="(entry, index) in obligation.history"
              :key="entry.occurredAt + entry.amountMinor + index"
              class="flex min-w-0 flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div class="min-w-0">
                <p class="break-words font-medium text-[var(--portal-color-ink)]">
                  {{ entry.methodLabel }}
                </p>
                <p class="portal-copy portal-copy--small">
                  {{ entry.occurredAt }}
                </p>
              </div>
              <div class="flex min-w-0 items-center gap-3 sm:justify-end">
                <strong class="break-words text-[var(--portal-color-ink)]">
                  <template v-if="entry.available && entry.amountMinor !== null && entry.currency">
                    {{ formatMoney(entry.amountMinor, entry.currency) }}
                  </template>
                  <template v-else>
                    {{ t('finance.entryUnavailable') }}
                  </template>
                </strong>
                <a
                  v-if="entry.receiptUrl"
                  :href="entry.receiptUrl"
                  class="portal-link shrink-0"
                >
                  {{ t('finance.receipt') }}
                </a>
              </div>
            </li>
          </ul>
        </div>
      </section>
    </section>
  </AppShell>
</template>
