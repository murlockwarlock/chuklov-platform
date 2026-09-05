<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type MoneyAmount = {
    currency: string;
    amountMinor: number;
};

type PartnerStats = {
    visits: number;
    registrations: number;
    paidClients: number;
    visitToRegistrationRate: number | null;
    registrationToPaidClientRate: number | null;
    rewardEarned: MoneyAmount[];
};

type Registration = {
    name: string;
    registeredAt: string | null;
    financeEvidenceRecorded: boolean;
    financeEvidenceAt: string | null;
    paidClient: boolean;
    linkName: string;
    channel: string;
};

type CampaignLink = {
    name: string;
    channel: string;
    isActive: boolean;
    createdAt: string | null;
    shareUrl: string;
    disableUrl: string;
    visits: number;
    registrations: number;
    paidClients: number;
    rewards: MoneyAmount[];
};

type RewardBalance = {
    currency: string;
    earnedMinor: number;
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
        isPartner: boolean;
        status: string | null;
        activatedAt: string | null;
        link: string;
        activationUrl: string;
        createLinkUrl: string;
        stats: PartnerStats;
        links: CampaignLink[];
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
const copiedUrl = ref<string | null>(null);
const sharedUrl = ref<string | null>(null);
const activationForm = useForm<Record<string, never>>({});
const linkForm = useForm<{ name: string; channel: string }>({ name: '', channel: 'telegram' });
const payoutForm = useForm<{ amount: string; currency: string; idempotency_key: string }>({
    amount: '',
    currency: props.referrals.rewards.balances.find((balance) => balance.availableMinor > 0)?.currency
        ?? props.referrals.rewards.balances[0]?.currency
        ?? '',
    idempotency_key: createIdempotencyKey('referral-payout'),
});

const requestableBalances = computed(() => props.referrals.rewards.balances.filter((balance) => balance.availableMinor > 0));
const channelOptions = computed(() => [
    { value: 'telegram', label: t('referrals.channelTelegram') },
    { value: 'instagram', label: t('referrals.channelInstagram') },
    { value: 'youtube', label: t('referrals.channelYouTube') },
    { value: 'whatsapp', label: t('referrals.channelWhatsApp') },
    { value: 'website', label: t('referrals.channelWebsite') },
    { value: 'other', label: t('referrals.channelOther') },
]);

function createIdempotencyKey(prefix: string): string {
    return `${prefix}-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;
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

function formatMoneyList(items: MoneyAmount[]): string {
    return items.length === 0 ? '—' : items.map((item) => formatMoney(item.amountMinor, item.currency)).join(' · ');
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function formatRate(value: number | null): string {
    return value === null ? '—' : `${value}%`;
}

function balanceSummary(key: 'availableMinor' | 'pendingPayoutMinor' | 'paidOutMinor'): string {
    return formatMoneyList(props.referrals.rewards.balances.map((balance) => ({
        currency: balance.currency,
        amountMinor: balance[key],
    })));
}

async function copyUrl(url: string): Promise<void> {
    try {
        let copied = false;

        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(url);
                copied = true;
            } catch {
                copied = false;
            }
        }

        if (!copied) {
            const field = document.createElement('textarea');
            field.value = url;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            const copied = document.execCommand('copy');
            field.remove();

            if (!copied) {
                throw new Error('copy_failed');
            }
        }

        copiedUrl.value = url;
        window.setTimeout(() => {
            if (copiedUrl.value === url) {
                copiedUrl.value = null;
            }
        }, 1800);
    } catch {
        copiedUrl.value = null;
    }
}

async function shareLink(link: CampaignLink): Promise<void> {
    if (navigator.share) {
        try {
            await navigator.share({ title: link.name, url: link.shareUrl });
            sharedUrl.value = link.shareUrl;
            window.setTimeout(() => {
                if (sharedUrl.value === link.shareUrl) {
                    sharedUrl.value = null;
                }
            }, 1800);

            return;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
        }
    }

    await copyUrl(link.shareUrl);
}

function activatePartner(): void {
    activationForm.post(props.referrals.activationUrl, { preserveScroll: true });
}

function createLink(): void {
    linkForm.post(props.referrals.createLinkUrl, {
        preserveScroll: true,
        onSuccess: () => linkForm.reset('name'),
    });
}

function disableLink(link: CampaignLink): void {
    if (! window.confirm(t('referrals.disableConfirm'))) {
        return;
    }

    router.post(link.disableUrl, {}, { preserveScroll: true });
}

function submitPayout(): void {
    payoutForm.post(props.referrals.rewards.requestUrl, {
        preserveScroll: true,
        onSuccess: () => {
            payoutForm.reset('amount');
            payoutForm.idempotency_key = createIdempotencyKey('referral-payout');
        },
    });
}

function cancelPayout(payout: Payout): void {
    router.post(payout.cancelUrl, { idempotency_key: createIdempotencyKey('referral-payout-cancel') }, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="props.referrals.isPartner ? t('referrals.partnerTitle') : t('referrals.becomeTitle')"
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
            {{ props.referrals.isPartner ? t('referrals.partnerTitle') : t('referrals.becomeTitle') }}
          </h1>
          <p class="portal-copy">
            {{ props.referrals.isPartner ? t('referrals.partnerDescription') : t('referrals.becomeDescription') }}
          </p>
        </div>
        <Link
          :href="props.portal.urls.home"
          class="portal-button portal-button--secondary"
        >
          {{ t('common.back') }}
        </Link>
      </header>

      <section
        v-if="!props.referrals.isPartner"
        class="portal-panel portal-panel--accent portal-stack portal-stack--tight"
        data-testid="partner-enrollment"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('referrals.activateTitle') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ t('referrals.activateDescription') }}
        </p>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          data-testid="partner-activate"
          :disabled="activationForm.processing"
          @click="activatePartner"
        >
          {{ activationForm.processing ? t('referrals.activating') : t('referrals.activate') }}
        </button>
      </section>

      <template v-else>
        <section
          class="grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
          data-testid="partner-summary"
        >
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.visits') }}</span>
            <strong class="break-words text-2xl text-[var(--portal-color-ink)]">{{ props.referrals.stats.visits }}</strong>
          </article>
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.registrations') }}</span>
            <strong class="break-words text-2xl text-[var(--portal-color-ink)]">{{ props.referrals.stats.registrations }}</strong>
          </article>
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.paidClients') }}</span>
            <strong class="break-words text-2xl text-[var(--portal-color-ink)]">{{ props.referrals.stats.paidClients }}</strong>
          </article>
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.available') }}</span>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">{{ balanceSummary('availableMinor') }}</strong>
          </article>
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.pendingPayout') }}</span>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">{{ balanceSummary('pendingPayoutMinor') }}</strong>
          </article>
          <article class="portal-panel portal-panel--compact portal-stack portal-stack--tight min-w-0">
            <span class="portal-copy portal-copy--small break-words">{{ t('referrals.paidOut') }}</span>
            <strong class="break-words text-lg text-[var(--portal-color-ink)]">{{ balanceSummary('paidOutMinor') }}</strong>
          </article>
        </section>

        <section class="portal-panel portal-stack portal-stack--tight">
          <div class="portal-section-heading">
            <div class="portal-stack portal-stack--tight">
              <h2 class="portal-heading portal-heading--section">
                {{ t('referrals.statisticsTitle') }}
              </h2>
              <p class="portal-copy portal-copy--small">
                {{ t('referrals.conversionVisitRegistration') }}: {{ formatRate(props.referrals.stats.visitToRegistrationRate) }} ·
                {{ t('referrals.conversionRegistrationPaid') }}: {{ formatRate(props.referrals.stats.registrationToPaidClientRate) }}
              </p>
            </div>
            <span class="portal-copy portal-copy--small">{{ formatMoneyList(props.referrals.stats.rewardEarned) }}</span>
          </div>
        </section>

        <section
          class="portal-stack"
          data-testid="partner-links"
        >
          <header class="portal-section-heading">
            <h2 class="portal-heading portal-heading--section">
              {{ t('referrals.linksTitle') }}
            </h2>
          </header>

          <form
            class="portal-panel portal-grid portal-grid--form"
            data-testid="partner-create-link-form"
            @submit.prevent="createLink"
          >
            <label class="portal-field">
              <span class="portal-label">{{ t('referrals.linkName') }}</span>
              <input
                v-model="linkForm.name"
                type="text"
                class="portal-input"
                maxlength="180"
                :placeholder="t('referrals.linkNamePlaceholder')"
                required
              >
              <span
                v-if="linkForm.errors.name"
                class="portal-copy text-[var(--portal-color-danger)]"
              >{{ linkForm.errors.name }}</span>
            </label>
            <label class="portal-field">
              <span class="portal-label">{{ t('referrals.channel') }}</span>
              <select
                v-model="linkForm.channel"
                class="portal-input"
                required
              >
                <option
                  v-for="channel in channelOptions"
                  :key="channel.value"
                  :value="channel.value"
                >
                  {{ channel.label }}
                </option>
              </select>
              <span
                v-if="linkForm.errors.channel"
                class="portal-copy text-[var(--portal-color-danger)]"
              >{{ linkForm.errors.channel }}</span>
            </label>
            <button
              type="submit"
              class="portal-button portal-button--primary self-start"
              data-testid="partner-create-link"
              :disabled="linkForm.processing"
            >
              {{ linkForm.processing ? t('referrals.creatingLink') : t('referrals.createLink') }}
            </button>
          </form>

          <div
            v-if="props.referrals.links.length"
            class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-2"
          >
            <article
              v-for="(link, index) in props.referrals.links"
              :key="link.shareUrl"
              class="portal-panel portal-stack portal-stack--tight min-w-0"
              :data-testid="`partner-link-${index}`"
            >
              <div class="portal-section-heading items-start">
                <div class="min-w-0">
                  <h3 class="portal-heading portal-heading--card break-words">
                    {{ link.name }}
                  </h3>
                  <p class="portal-copy portal-copy--small">
                    {{ link.channel }} · {{ link.isActive ? t('referrals.activeLink') : t('referrals.disabledLink') }}
                  </p>
                </div>
                <span class="portal-copy portal-copy--small shrink-0">{{ formatDate(link.createdAt) }}</span>
              </div>
              <code class="block max-w-full break-all rounded-lg bg-[var(--portal-color-surface-muted)] p-3 text-sm text-[var(--portal-color-ink)]">{{ link.shareUrl }}</code>
              <div class="grid min-w-0 grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <span class="portal-copy portal-copy--small">{{ t('referrals.visits') }}</span>
                  <strong class="block break-words text-[var(--portal-color-ink)]">{{ link.visits }}</strong>
                </div>
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <span class="portal-copy portal-copy--small">{{ t('referrals.registrations') }}</span>
                  <strong class="block break-words text-[var(--portal-color-ink)]">{{ link.registrations }}</strong>
                </div>
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <span class="portal-copy portal-copy--small">{{ t('referrals.paidClients') }}</span>
                  <strong class="block break-words text-[var(--portal-color-ink)]">{{ link.paidClients }}</strong>
                </div>
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <span class="portal-copy portal-copy--small">{{ t('referrals.earned') }}</span>
                  <strong class="block break-words text-[var(--portal-color-ink)]">{{ formatMoneyList(link.rewards) }}</strong>
                </div>
              </div>
              <div class="flex min-w-0 flex-wrap gap-2">
                <button
                  type="button"
                  class="portal-button portal-button--secondary"
                  :data-testid="`partner-link-copy-${index}`"
                  @click="copyUrl(link.shareUrl)"
                >
                  {{ copiedUrl === link.shareUrl ? t('referrals.copied') : t('referrals.copy') }}
                </button>
                <button
                  type="button"
                  class="portal-button portal-button--secondary"
                  :data-testid="`partner-link-share-${index}`"
                  @click="shareLink(link)"
                >
                  {{ sharedUrl === link.shareUrl ? t('referrals.shared') : t('referrals.share') }}
                </button>
                <button
                  v-if="link.isActive"
                  type="button"
                  class="portal-button portal-button--secondary"
                  :data-testid="`partner-link-disable-${index}`"
                  @click="disableLink(link)"
                >
                  {{ t('referrals.disableLink') }}
                </button>
              </div>
            </article>
          </div>
          <p
            v-else
            class="portal-copy"
          >
            {{ t('referrals.noLinks') }}
          </p>
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
              :data-testid="`partner-balance-${balance.currency}`"
            >
              <strong class="text-xl text-[var(--portal-color-ink)]">{{ balance.currency }}</strong>
              <div class="grid min-w-0 grid-cols-2 gap-2 lg:grid-cols-4">
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <p class="portal-copy portal-copy--small">
                    {{ t('referrals.earned') }}
                  </p>
                  <strong class="break-words text-[var(--portal-color-ink)]">{{ formatMoney(balance.earnedMinor, balance.currency) }}</strong>
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
                <div class="min-w-0 rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-3">
                  <p class="portal-copy portal-copy--small">
                    {{ t('referrals.paidOut') }}
                  </p>
                  <strong class="break-words text-[var(--portal-color-ink)]">{{ formatMoney(balance.paidOutMinor, balance.currency) }}</strong>
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
              v-model="payoutForm.currency"
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
              v-if="payoutForm.errors.currency"
              class="portal-copy text-[var(--portal-color-danger)]"
            >{{ payoutForm.errors.currency }}</span>
          </label>
          <label class="portal-field">
            <span class="portal-label">{{ t('referrals.amount') }}</span>
            <input
              v-model="payoutForm.amount"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              class="portal-input"
              :placeholder="t('referrals.amountPlaceholder')"
            >
            <span
              v-if="payoutForm.errors.amount"
              class="portal-copy text-[var(--portal-color-danger)]"
            >{{ payoutForm.errors.amount }}</span>
          </label>
          <button
            type="button"
            class="portal-button portal-button--primary self-start"
            :disabled="payoutForm.processing"
            @click="submitPayout"
          >
            {{ payoutForm.processing ? t('referrals.sendingPayout') : t('referrals.requestPayout') }}
          </button>
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
            class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2"
          >
            <article
              v-for="(registration, index) in props.referrals.registrations"
              :key="registration.name + (registration.registeredAt ?? '') + index"
              class="portal-card portal-stack portal-stack--tight min-w-0"
            >
              <strong class="break-words text-[var(--portal-color-ink)]">{{ registration.name }}</strong>
              <span class="portal-card__summary">{{ registration.linkName }} · {{ registration.channel }}</span>
              <span class="portal-card__summary">{{ formatDate(registration.registeredAt) }}</span>
              <span class="portal-card__summary">{{ registration.paidClient ? t('referrals.paidClient') : t('referrals.financeEvidenceMissing') }}</span>
            </article>
          </div>
          <p
            v-else
            class="portal-copy"
          >
            {{ t('referrals.empty') }}
          </p>
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
                :data-testid="`partner-payout-${index}`"
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
      </template>
    </section>
  </AppShell>
</template>
