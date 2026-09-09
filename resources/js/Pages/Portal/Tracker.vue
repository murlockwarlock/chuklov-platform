<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Access = { allowed: boolean; enabled: boolean; freeMode: boolean; statusLabel: string; planName: string | null; startsAt: string | null; endsAt: string | null };
type Plan = { name: string; price: string | null; description: string | null; durationDays: number };
type HistoryEntry = { occurredAt: string; note: string };

const props = defineProps<{ portal: PortalShell; tracker: { access: Access; plans: Plan[]; history: HistoryEntry[] }; urls: { checkIn: string; specialist: string } }>();
const { t, locale } = usePortalLocale();
const form = useForm<{ note: string }>({ note: '' });
const formatDate = (value: string): string => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const description = computed(() => props.tracker.access.freeMode ? t('tracker.freeDescription') : t('tracker.accessDescription'));
function submit(): void { form.post(props.urls.checkIn, { preserveScroll: true, onSuccess: () => form.reset() }); }
</script>

<template>
  <AppShell
    :title="t('tracker.title')"
    :portal="props.portal"
    active="tracker"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('tracker.title') }}
          </h1>
          <p class="portal-copy">
            {{ description }}
          </p>
        </div>
        <Link
          :href="props.urls.specialist"
          class="portal-button portal-button--secondary"
        >
          {{ t('tracker.specialist') }}
        </Link>
      </header>

      <section class="portal-panel portal-stack portal-stack--tight">
        <strong class="portal-heading portal-heading--section">{{ props.tracker.access.statusLabel }}</strong>
        <p
          v-if="props.tracker.access.planName"
          class="portal-copy"
        >
          {{ props.tracker.access.planName }}
        </p>
      </section>

      <section
        v-if="props.tracker.access.allowed"
        class="portal-panel portal-stack"
      >
        <h2 class="portal-heading portal-heading--section">
          {{ t('tracker.checkInTitle') }}
        </h2>
        <form
          class="portal-stack portal-stack--tight"
          @submit.prevent="submit"
        >
          <textarea
            v-model="form.note"
            class="portal-input"
            rows="5"
            :placeholder="t('tracker.checkInPlaceholder')"
            maxlength="5000"
          />
          <button
            type="submit"
            class="portal-button portal-button--primary"
            :disabled="form.processing"
          >
            {{ form.processing ? t('tracker.saving') : t('tracker.save') }}
          </button>
        </form>
      </section>

      <section
        v-if="props.tracker.access.allowed"
        class="portal-stack"
      >
        <h2 class="portal-heading portal-heading--section">
          {{ t('tracker.history') }}
        </h2>
        <EmptyState
          v-if="!props.tracker.history.length"
          :title="t('tracker.emptyHistory')"
        />
        <div
          v-else
          class="portal-panel divide-y divide-[var(--portal-color-border)]"
        >
          <article
            v-for="entry in props.tracker.history"
            :key="entry.occurredAt"
            class="portal-stack portal-stack--tight py-4 first:pt-0 last:pb-0"
          >
            <time class="portal-copy portal-copy--small">{{ formatDate(entry.occurredAt) }}</time>
            <p class="portal-copy whitespace-pre-wrap">
              {{ entry.note }}
            </p>
          </article>
        </div>
      </section>

      <section
        v-else
        class="portal-stack"
      >
        <h2 class="portal-heading portal-heading--section">
          {{ t('tracker.plansTitle') }}
        </h2>
        <p
          v-if="!props.tracker.plans.length"
          class="portal-copy"
        >
          {{ t('tracker.noPlans') }}
        </p>
        <div
          v-else
          class="grid grid-cols-1 gap-4 md:grid-cols-2"
        >
          <article
            v-for="plan in props.tracker.plans"
            :key="plan.name"
            class="portal-panel portal-stack portal-stack--tight"
          >
            <h3 class="portal-heading portal-heading--section">
              {{ plan.name }}
            </h3>
            <p
              v-if="plan.description"
              class="portal-copy"
            >
              {{ plan.description }}
            </p>
            <strong class="portal-copy">{{ plan.price }} · {{ plan.durationDays }} {{ t('tracker.days') }}</strong>
            <p class="portal-copy portal-copy--small">
              {{ t('tracker.paymentUnavailable') }}
            </p>
          </article>
        </div>
      </section>
    </section>
  </AppShell>
</template>
