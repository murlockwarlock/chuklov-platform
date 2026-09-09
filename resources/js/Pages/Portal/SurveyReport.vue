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
    active="health"
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
          <h1 class="portal-heading portal-heading--section">
            {{ props.report.title }}
          </h1>
        </div>
      </header>
      <section class="portal-panel portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('survey.metrics') }}
        </h2>
        <dl class="portal-report-metrics">
          <div
            v-for="(metric, key) in props.report.metrics"
            :key="key"
            class="portal-report-metric"
          >
            <dt>
              {{ metric.label }}
            </dt>
            <dd>
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
          class="portal-list"
        >
          <li
            v-for="threshold in props.report.thresholds"
            :key="`${threshold.metric_key}-${threshold.tag}`"
            class="portal-list__row"
          >
            {{ threshold.label }}
          </li>
        </ul>
      </section>
    </section>
  </AppShell>
</template>
