<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Registration = {
    name: string;
    registeredAt: string | null;
    financeEvidenceRecorded: boolean;
    financeEvidenceAt: string | null;
};

type RewardBalance = {
    currency: string;
    accruedMinor: number;
    availableMinor: number;
    pendingPayoutMinor: number;
    paidOutMinor: number;
};

type RewardHistoryEntry = {
    typeLabel: string;
    isReversal: boolean;
    amountMinor: number;
    currency: string;
    clientName: string | null;
    reason: string | null;
    occurredAt: string | null;
};

type Payout = {
    amountMinor: number;
    currency: string;
    requestedAt: string | null;
    statusLabel: string;
    rejectionReason: string | null;
    canCancel: boolean;
    cancelUrl: string;
};

const props = defineProps<{
    portal: PortalShell;
    referrals: {
        link: string;
        referredClientsCount: number;
        registrations: Registration[];
        rewards: {
            balances: RewardBalance[];
            history: RewardHistoryEntry[];
            payouts: Payout[];
            requestUrl: string;
        };
    };
}>();

const { t, locale } = usePortalLocale();
const copied = ref(false);
const form = useForm<{
    amount: string;
    currency: string;
    idempotency_key: string;
}>({
    amount: '',
    currency: props.referrals.rewards.balances.find((balance) => balance.availableMinor > 0)?.currency
        ?? props.referrals.rewards.balances[0]?.currency
        ?? '',
    idempotency_key: `referral-payout-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`,
});

const requestableBalances = computed(() => props.referrals.rewards.balances.filter((balance) => balance.availableMinor > 0));

function formatMoney(minor: number, currency: string): string {
    const digits = currency === 'JPY' ? 0 : 2;

    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(minor / (10 ** digits));
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.referrals.link);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 1800);
    } catch {
        copied.value = false;
    }
}

function submitPayout(): void {
    form.post(props.referrals.rewards.requestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount');
            form.idempotency_key = `referral-payout-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;
        },
    });
}

function cancelPayout(payout: Payout): void {
    router.post(payout.cancelUrl, {
        idempotency_key: `referral-payout-cancel-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`,
    }, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="t('referrals.title')"
    :portal="props.portal"
    active="referrals"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('referrals.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('referrals.description') }}
          </p>
        </div>
        <Link
          :href="props.portal.urls.home"
          class="portal-button portal-button--secondary"
        >
          {{ t('common.back') }}
        </Link>
      </header>

      <section class="portal-panel portal-stack portal-stack--tight">
        <span class="portal-label">{{ t('referrals.linkLabel') }}</span>
        <code class="break-all rounded-lg bg-[var(--portal-color-surface-muted)] p-3 text-sm text-[var(--portal-color-ink)]">{{ props.referrals.link }}</code>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          @click="copyLink"
        >
          {{ copied ? t('referrals.copied') : t('referrals.copy') }}
        </button>
      </section>

      <section class="portal-stack">
        <div class="portal-section-heading">
          <h2 class="portal-heading portal-heading--section">
            {{ t('referrals.rewardsTitle') }}
          </h2>
        </div>
        <div
          v-if="props.referrals.rewards.balances.length"
          class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2"
        >
          <article
            v-for="balance in props.referrals.rewards.balances"
            :key="balance.currency"
            class="portal-panel portal-stack portal-stack--tight"
          >
            <strong class="text-xl text-[var(--portal-color-ink)]">{{ balance.currency }}</strong>
            <div class="grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-3">
              <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                <p class="portal-copy portal-copy--small">
                  {{ t('referrals.accrued') }}
                </p>
                <strong class="break-words text-[var(--portal-color-ink)]">{{ formatMoney(balance.accruedMinor, balance.currency) }}</strong>
              </div>
              <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                <p class="portal-copy portal-copy--small">
                  {{ t('referrals.available') }}
                </p>
                <strong class="break-words text-[var(--portal-color-ink)]">{{ formatMoney(balance.availableMinor, balance.currency) }}</strong>
              </div>
              <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                <p class="portal-copy portal-copy--small">
                  {{ t('referrals.pendingPayout') }}
                </p>
                <strong class="break-words text-[var(--portal-color-ink)]">{{ formatMoney(balance.pendingPayoutMinor, balance.currency) }}</strong>
              </div>
            </div>
          </article>
        </div>
        <p
          v-else
          class="portal-copy"
        >
          {{ t('referrals.noRewards') }}
        </p>
      </section>

      <section
        v-if="requestableBalances.length"
        class="portal-panel portal-stack"
      >
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--section">
            {{ t('referrals.requestPayout') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ t('referrals.requestPayoutHint') }}
          </p>
        </div>
        <label class="portal-field">
          <span class="portal-label">{{ t('referrals.currency') }}</span>
          <select
            v-model="form.currency"
            class="portal-input"
          >
            <option
              v-for="balance in requestableBalances"
              :key="balance.currency"
              :value="balance.currency"
            >
              {{ balance.currency }} — {{ formatMoney(balance.availableMinor, balance.currency) }}
            </option>
          </select>
          <span
            v-if="form.errors.currency"
            class="portal-copy text-[var(--portal-color-danger)]"
          >{{ form.errors.currency }}</span>
        </label>
        <label class="portal-field">
          <span class="portal-label">{{ t('referrals.amount') }}</span>
          <input
            v-model="form.amount"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="portal-input"
            :placeholder="t('referrals.amountPlaceholder')"
          >
          <span
            v-if="form.errors.amount"
            class="portal-copy text-[var(--portal-color-danger)]"
          >{{ form.errors.amount }}</span>
        </label>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          :disabled="form.processing"
          @click="submitPayout"
        >
          {{ form.processing ? t('referrals.sendingPayout') : t('referrals.requestPayout') }}
        </button>
      </section>

      <section class="portal-stack">
        <div class="portal-section-heading">
          <h2 class="portal-heading portal-heading--section">
            {{ t('referrals.payoutHistory') }}
          </h2>
        </div>
        <div
          v-if="props.referrals.rewards.payouts.length"
          class="min-w-0 overflow-hidden rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)]"
        >
          <ul class="divide-y divide-[var(--portal-color-border)]">
            <li
              v-for="(payout, index) in props.referrals.rewards.payouts"
              :key="(payout.requestedAt ?? '') + payout.amountMinor + payout.currency + index"
              class="flex min-w-0 flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
              <div class="min-w-0">
                <p class="break-words font-medium text-[var(--portal-color-ink)]">
                  {{ formatMoney(payout.amountMinor, payout.currency) }}
                </p>
                <p class="portal-copy portal-copy--small">
                  {{ payout.statusLabel }} · {{ formatDate(payout.requestedAt) }}
                </p>
                <p
                  v-if="payout.rejectionReason"
                  class="portal-copy portal-copy--small"
                >
                  {{ payout.rejectionReason }}
                </p>
              </div>
              <button
                v-if="payout.canCancel"
                type="button"
                class="portal-button portal-button--secondary self-start sm:self-auto"
                @click="cancelPayout(payout)"
              >
                {{ t('referrals.cancelPayout') }}
              </button>
            </li>
          </ul>
        </div>
        <p
          v-else
          class="portal-copy"
        >
          {{ t('referrals.noPayouts') }}
        </p>
      </section>

      <section class="portal-stack">
        <div class="portal-section-heading">
          <h2 class="portal-heading portal-heading--section">
            {{ t('referrals.registrations') }}
          </h2>
          <span class="portal-copy portal-copy--small">{{ props.referrals.referredClientsCount }}</span>
        </div>
        <div
          v-if="props.referrals.registrations.length"
          class="portal-grid portal-grid--cards"
        >
          <article
            v-for="registration in props.referrals.registrations"
            :key="registration.name + (registration.registeredAt ?? '')"
            class="portal-card portal-stack portal-stack--tight"
          >
            <strong class="text-[var(--portal-color-ink)]">{{ registration.name }}</strong>
            <span class="portal-card__summary">{{ t('referrals.registered') }}</span>
            <span class="portal-card__summary">{{ registration.financeEvidenceRecorded ? t('referrals.financeEvidence') : t('referrals.financeEvidenceMissing') }}</span>
          </article>
        </div>
        <p
          v-else
          class="portal-copy"
        >
          {{ t('referrals.empty') }}
        </p>
      </section>

      <section
        v-if="props.referrals.rewards.history.length"
        class="portal-stack"
      >
        <div class="portal-section-heading">
          <h2 class="portal-heading portal-heading--section">
            {{ t('referrals.rewardHistory') }}
          </h2>
        </div>
        <ul class="min-w-0 divide-y divide-[var(--portal-color-border)] rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)]">
          <li
            v-for="(entry, index) in props.referrals.rewards.history"
            :key="(entry.occurredAt ?? '') + entry.amountMinor + entry.currency + index"
            class="flex min-w-0 flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <p class="break-words font-medium text-[var(--portal-color-ink)]">
                {{ entry.typeLabel }}<span v-if="entry.clientName"> · {{ entry.clientName }}</span>
              </p>
              <p class="portal-copy portal-copy--small">
                {{ formatDate(entry.occurredAt) }}
              </p>
            </div>
            <strong :class="entry.isReversal ? 'text-[var(--portal-color-danger)]' : 'text-[var(--portal-color-ink)]'">
              {{ entry.isReversal ? '−' : '+' }}{{ formatMoney(entry.amountMinor, entry.currency) }}
            </strong>
          </li>
        </ul>
      </section>
    </section>
  </AppShell>
</template>
