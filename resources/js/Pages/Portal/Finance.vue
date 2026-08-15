<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type FinanceHistory = {
    amountMinor: number;
    currency: string;
    occurredAt: string;
    methodLabel: string;
    receiptUrl: string | null;
};

type Obligation = {
    serviceName: string;
    bookingUrl: string | null;
    completedAt: string | null;
    obligationMinor: number;
    paidMinor: number;
    outstandingMinor: number;
    displayCurrency: string;
    originalCurrency: string;
    status: 'outstanding' | 'partially_paid' | 'settled';
    statusLabel: string;
    history: FinanceHistory[];
};

type Total = { amountMinor: number; currency: string };

const props = defineProps<{
    portal: PortalShell;
    obligations: Obligation[];
    totals: Total[];
    urls: { home: string; bookings: string };
}>();

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
</script>

<template>
  <Head :title="t('finance.title')" />
  <AppShell
    :title="t('finance.title')"
    :portal="props.portal"
    active="finance"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('finance.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('finance.description') }}
          </p>
        </div>
        <Link
          :href="props.urls.bookings"
          class="portal-button portal-button--secondary"
        >
          {{ t('finance.backBookings') }}
        </Link>
      </header>

      <section
        v-if="props.totals.length"
        class="grid grid-cols-1 gap-4 sm:grid-cols-2"
      >
        <article
          v-for="total in props.totals"
          :key="total.currency"
          class="portal-panel portal-stack portal-stack--tight"
        >
          <p class="portal-eyebrow">
            {{ t('finance.totalOutstanding') }}
          </p>
          <strong class="text-2xl tracking-tight text-[var(--portal-color-ink)] sm:text-3xl">
            {{ formatMoney(total.amountMinor, total.currency) }}
          </strong>
        </article>
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
        class="portal-panel portal-stack"
      >
        <header class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <h2 class="portal-heading portal-heading--section break-words">
              {{ obligation.serviceName }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ obligation.completedAt ?? t('finance.visitDateUnavailable') }}
              <span v-if="obligation.originalCurrency !== obligation.displayCurrency">
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

        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-4">
            <p class="portal-copy portal-copy--small">
              {{ t('finance.obligation') }}
            </p>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">
              {{ formatMoney(obligation.obligationMinor, obligation.displayCurrency) }}
            </strong>
          </div>
          <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-4">
            <p class="portal-copy portal-copy--small">
              {{ t('finance.paid') }}
            </p>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">
              {{ formatMoney(obligation.paidMinor, obligation.displayCurrency) }}
            </strong>
          </div>
          <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-4">
            <p class="portal-copy portal-copy--small">
              {{ t('finance.remaining') }}
            </p>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">
              {{ formatMoney(obligation.outstandingMinor, obligation.displayCurrency) }}
            </strong>
          </div>
        </div>

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
                  {{ formatMoney(entry.amountMinor, entry.currency) }}
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
