<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Tracker = {
    access: { allowed: boolean };
    today: Array<{ id: number; title: string; status: string }>;
    program: Array<{ id: number }>;
};
type Survey = {
    definitions: Array<{ id: number; title: string; description: string | null; questionCount: number }>;
    attempts: Array<{ id: number; title: string; status: string; reportId: number | null }>;
};

const props = defineProps<{
    portal: PortalShell;
    tracker: Tracker;
    surveys: Survey;
    urls: { tracker: string; surveys: string };
}>();

const { t } = usePortalLocale();
const hasTests = props.surveys.definitions.length > 0 || props.surveys.attempts.length > 0;
const trackerUrl = props.urls.tracker;
</script>

<template>
  <AppShell
    :title="t('health.title')"
    :portal="props.portal"
    active="health"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-stack portal-stack--tight">
        <p class="portal-eyebrow">
          {{ t('health.eyebrow') }}
        </p>
        <h1 class="portal-heading portal-heading--section">
          {{ t('health.title') }}
        </h1>
        <p class="portal-copy">
          {{ t('health.description') }}
        </p>
      </header>

      <nav
        class="portal-list"
        :aria-label="t('health.title')"
      >
        <Link
          :href="trackerUrl"
          class="portal-list__row"
          data-testid="health-tracker-link"
        >
          <span>
            <strong class="portal-list__title">{{ t('health.tracker') }}</strong>
            <span class="portal-list__summary">{{ t('health.trackerDescription') }}</span>
          </span>
          <span class="portal-list__chevron"><PortalIcon name="arrow" /></span>
        </Link>
        <Link
          v-if="hasTests"
          :href="props.urls.surveys"
          class="portal-list__row"
          data-testid="health-tests-link"
        >
          <span>
            <strong class="portal-list__title">{{ t('health.tests') }}</strong>
            <span class="portal-list__summary">{{ t('health.testsDescription') }}</span>
          </span>
          <span class="portal-list__chevron"><PortalIcon name="arrow" /></span>
        </Link>
      </nav>
    </section>
  </AppShell>
</template>
