<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Evidence = { question: string; answer: string; score: number };
type AttentionArea = {
    label: string;
    score: number;
    status: string;
    reason: string;
    evidence: Evidence[];
    observation: string;
};
type RoadMapItem = { title: string; description: string; category: string };
type ComparisonItem = { label: string; before: number; after: number; change: number };
type Report = {
    title: string;
    summary?: { short?: string };
    attention_areas?: AttentionArea[];
    domains?: Array<{ label: string; score: number; raw_score: number; status: string }>;
    safe_steps?: string[];
    specialist_questions?: string[];
    road_map?: { title: string; description: string; items: RoadMapItem[] };
    comparison?: { message: string; items: ComparisonItem[] } | null;
    disclaimer?: string;
    ctas?: { road_map: string; companion: string; repeat: string };
    metrics?: Record<string, { label: string; value: number; normalized_score: number | null; max_value: number | null }>;
    thresholds?: Array<{ label: string }>;
};

const props = defineProps<{
    portal: PortalShell;
    report: Report;
    urls: { index: string; companion: string; repeat: string };
}>();

const { t } = usePortalLocale();
const attentionAreas = computed(() => props.report.attention_areas ?? []);
const domains = computed(() => props.report.domains ?? []);
const safeSteps = computed(() => props.report.safe_steps ?? []);
const specialistQuestions = computed(() => props.report.specialist_questions ?? []);
const roadMapItems = computed(() => props.report.road_map?.items ?? []);
const metrics = computed(() => props.report.metrics ?? {});

const scoreLabel = (score: number): string => t('survey.score', { value: score });
const changeLabel = (change: number): string => change > 0 ? `+${change}` : String(change);
</script>

<template>
  <AppShell
    :title="t('survey.reportTitle')"
    :portal="props.portal"
    active="health"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <Link
        :href="props.urls.index"
        class="portal-link"
      >
        {{ t('survey.back') }}
      </Link>

      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight min-w-0">
          <p class="portal-eyebrow">
            {{ t('survey.reportTitle') }}
          </p>
          <h1 class="portal-heading portal-heading--section break-words">
            {{ props.report.title }}
          </h1>
          <p
            v-if="props.report.summary?.short"
            class="portal-copy"
          >
            {{ props.report.summary.short }}
          </p>
        </div>
      </header>

      <section
        class="portal-panel portal-stack portal-stack--tight"
        :aria-label="t('survey.nextSteps')"
        data-testid="portal-report-actions"
      >
        <p class="portal-copy portal-copy--small">
          {{ t('survey.nextSteps') }}
        </p>
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap">
          <a
            href="#road-map"
            class="portal-button portal-button--primary"
          >
            {{ props.report.ctas?.road_map ?? t('survey.openRoadMap') }}
          </a>
          <Link
            :href="props.urls.companion"
            class="portal-button portal-button--secondary"
          >
            {{ t('survey.discussCompanion') }}
          </Link>
          <Link
            :href="props.urls.repeat"
            method="post"
            as="button"
            class="portal-button portal-button--secondary"
          >
            {{ props.report.ctas?.repeat ?? t('survey.repeat') }}
          </Link>
        </div>
      </section>

      <section
        class="portal-panel portal-stack"
        aria-labelledby="survey-attention-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <h2
            id="survey-attention-heading"
            class="portal-heading portal-heading--section"
          >
            {{ t('survey.attention') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ t('survey.attentionDescription') }}
          </p>
        </div>

        <div class="grid min-w-0 gap-4 lg:grid-cols-3">
          <article
            v-for="(area, index) in attentionAreas"
            :key="area.label + index"
            class="min-w-0 rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)] bg-[var(--portal-color-surface-muted)] p-4"
          >
            <div class="flex min-w-0 items-start justify-between gap-3">
              <h3 class="min-w-0 break-words font-semibold text-[var(--portal-color-ink)]">
                {{ area.label }}
              </h3>
              <span class="shrink-0 rounded-full bg-[var(--portal-color-surface)] px-2.5 py-1 text-xs font-semibold text-[var(--portal-color-brand-strong)]">
                {{ area.score }}/100
              </span>
            </div>
            <p class="mt-3 text-sm font-medium text-[var(--portal-color-ink-soft)]">
              {{ area.status }}
            </p>
            <div class="mt-4 space-y-3 text-sm leading-6 text-[var(--portal-color-ink-soft)]">
              <div>
                <p class="font-semibold text-[var(--portal-color-ink)]">
                  {{ t('survey.evidence') }}
                </p>
                <ul
                  v-if="area.evidence.length"
                  class="mt-1 list-disc space-y-1 pl-5"
                >
                  <li
                    v-for="evidence in area.evidence"
                    :key="evidence.question"
                    class="break-words"
                  >
                    {{ evidence.question }} — {{ evidence.answer }}
                  </li>
                </ul>
                <p
                  v-else
                  class="mt-1"
                >
                  {{ t('survey.noEvidence') }}
                </p>
              </div>
              <div>
                <p class="font-semibold text-[var(--portal-color-ink)]">
                  {{ t('survey.observation') }}
                </p>
                <p class="mt-1 break-words">
                  {{ area.observation }}
                </p>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section
        v-if="safeSteps.length"
        class="portal-panel portal-stack"
        aria-labelledby="survey-safe-steps-heading"
      >
        <h2
          id="survey-safe-steps-heading"
          class="portal-heading portal-heading--section"
        >
          {{ t('survey.safeSteps') }}
        </h2>
        <ul class="portal-list">
          <li
            v-for="step in safeSteps"
            :key="step"
            class="portal-list__row"
          >
            <span class="break-words">{{ step }}</span>
          </li>
        </ul>
      </section>

      <section
        v-if="specialistQuestions.length"
        class="portal-panel portal-stack"
        aria-labelledby="survey-specialist-questions-heading"
      >
        <h2
          id="survey-specialist-questions-heading"
          class="portal-heading portal-heading--section"
        >
          {{ t('survey.specialistQuestions') }}
        </h2>
        <ul class="portal-list">
          <li
            v-for="question in specialistQuestions"
            :key="question"
            class="portal-list__row"
          >
            <span class="break-words">{{ question }}</span>
          </li>
        </ul>
      </section>

      <section
        id="road-map"
        class="portal-panel portal-panel--accent portal-stack scroll-mt-6"
        aria-labelledby="survey-road-map-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <h2
            id="survey-road-map-heading"
            class="portal-heading portal-heading--section"
          >
            {{ props.report.road_map?.title ?? t('survey.roadMap') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ props.report.road_map?.description ?? t('survey.roadMapDescription') }}
          </p>
        </div>
        <ol class="portal-list">
          <li
            v-for="(item, index) in roadMapItems"
            :key="item.title + index"
            class="portal-list__row items-start"
          >
            <span class="flex min-w-0 gap-3">
              <span class="shrink-0 font-semibold text-[var(--portal-color-brand-strong)]">{{ index + 1 }}</span>
              <span class="min-w-0">
                <strong class="portal-list__title break-words">{{ item.title }}</strong>
                <span class="portal-list__summary break-words">{{ item.description }}</span>
              </span>
            </span>
          </li>
        </ol>
      </section>

      <section
        v-if="props.report.comparison"
        class="portal-panel portal-stack"
        aria-labelledby="survey-comparison-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <h2
            id="survey-comparison-heading"
            class="portal-heading portal-heading--section"
          >
            {{ t('survey.comparison') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ props.report.comparison.message }}
          </p>
        </div>
        <div class="min-w-0 overflow-hidden rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)]">
          <div class="grid min-w-0 grid-cols-[minmax(0,1fr)_auto_auto_auto] gap-3 border-b border-[var(--portal-color-border)] px-4 py-3 text-xs font-semibold text-[var(--portal-color-ink-soft)]">
            <span>{{ t('survey.metrics') }}</span>
            <span>{{ t('survey.before') }}</span>
            <span>{{ t('survey.after') }}</span>
            <span>{{ t('survey.change') }}</span>
          </div>
          <ul class="divide-y divide-[var(--portal-color-border)]">
            <li
              v-for="item in props.report.comparison.items"
              :key="item.label"
              class="grid min-w-0 grid-cols-[minmax(0,1fr)_auto_auto_auto] items-center gap-3 px-4 py-3 text-sm"
            >
              <span class="min-w-0 break-words text-[var(--portal-color-ink)]">{{ item.label }}</span>
              <span class="text-[var(--portal-color-ink-soft)]">{{ item.before }}</span>
              <span class="text-[var(--portal-color-ink)]">{{ item.after }}</span>
              <span class="font-semibold text-[var(--portal-color-brand-strong)]">{{ changeLabel(item.change) }}</span>
            </li>
          </ul>
        </div>
      </section>
      <p
        v-else
        class="portal-copy portal-copy--small"
      >
        {{ t('survey.noComparison') }}
      </p>

      <p
        v-if="props.report.disclaimer"
        class="portal-copy portal-copy--small"
      >
        {{ props.report.disclaimer }}
      </p>

      <details
        v-if="Object.keys(metrics).length || domains.length"
        class="portal-panel portal-stack portal-stack--tight"
        data-testid="portal-report-metrics"
      >
        <summary class="cursor-pointer font-semibold text-[var(--portal-color-ink)]">
          {{ t('survey.technicalDetails') }}
        </summary>
        <dl class="portal-report-metrics">
          <div
            v-for="metric in domains"
            :key="metric.label"
            class="grid min-w-0 gap-2 border-t border-[var(--portal-color-border)] py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4"
          >
            <dt class="min-w-0">
              <span class="block break-words text-[var(--portal-color-ink)]">{{ metric.label }}</span>
              <span class="mt-1 block break-words text-sm font-medium text-[var(--portal-color-ink-soft)]">{{ t('survey.burdenLevel') }}: {{ metric.status }}</span>
            </dt>
            <dd class="m-0 break-words text-left text-base font-semibold text-[var(--portal-color-ink)] sm:text-right">
              {{ scoreLabel(metric.score) }}
            </dd>
          </div>
        </dl>
      </details>
    </section>
  </AppShell>
</template>
