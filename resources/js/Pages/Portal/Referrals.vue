<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Registration = {
    name: string;
    registeredAt: string | null;
    paidConversionObserved: boolean;
    paidConversionAt: string | null;
};

const props = defineProps<{
    portal: PortalShell;
    referrals: {
        link: string;
        registrations: Registration[];
    };
}>();

const { t } = usePortalLocale();
const copied = ref(false);

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.referrals.link);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 1800);
    } catch {
        copied.value = false;
    }
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
            {{ t('referrals.registrations') }}
          </h2>
          <span class="portal-copy portal-copy--small">{{ props.referrals.registrations.length }}</span>
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
            <span class="portal-card__summary">{{ registration.paidConversionObserved ? t('referrals.paid') : t('referrals.notPaid') }}</span>
          </article>
        </div>
        <p
          v-else
          class="portal-copy"
        >
          {{ t('referrals.empty') }}
        </p>
      </section>
    </section>
  </AppShell>
</template>
