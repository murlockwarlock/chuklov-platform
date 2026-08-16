<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Metric = { label: string; value: number };
type Threshold = { metric_key: string; tag: string; label: string };
const props = defineProps<{
    portal: PortalShell;
    report: { title: string; metrics: Record<string, Metric>; thresholds: Threshold[]; tags: string[] };
    urls: { index: string };
}>();
const { t } = usePortalLocale();
</script>

<template>
  <AppShell
    :title="t('survey.reportTitle')"
    :portal="props.portal"
    active="surveys"
  >
    <section class="portal-container portal-stack portal-stack--loose">
      <Link
        :href="props.urls.index"
        class="portal-link"
      >
        {{ t('survey.back') }}
      </Link>
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ t('survey.reportTitle') }}
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ props.report.title }}
          </h1>
        </div>
      </header>
      <section class="portal-panel portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('survey.metrics') }}
        </h2>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div
            v-for="(metric, key) in props.report.metrics"
            :key="key"
            class="rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-4"
          >
            <dt class="portal-copy portal-copy--small">
              {{ metric.label }}
            </dt>
            <dd class="text-2xl font-semibold text-[var(--portal-color-ink)]">
              {{ metric.value }}
            </dd>
          </div>
        </dl>
      </section>
      <section class="portal-panel portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('survey.thresholds') }}
        </h2>
        <p
          v-if="!props.report.thresholds.length"
          class="portal-copy"
        >
          {{ t('survey.noThresholds') }}
        </p>
        <ul
          v-else
          class="grid gap-2"
        >
          <li
            v-for="threshold in props.report.thresholds"
            :key="`${threshold.metric_key}-${threshold.tag}`"
            class="rounded-[var(--portal-radius-md)] bg-[var(--portal-color-surface-muted)] p-4 text-[var(--portal-color-ink)]"
          >
            {{ threshold.label }}
          </li>
        </ul>
      </section>
    </section>
  </AppShell>
</template>
