<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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
type HealthHistoryItem = {
    id: number;
    occurredAt: string | null;
    service: string | null;
    specialist: string | null;
    result: string | null;
    attachments: Array<{ filename: string; mimeType: string; sizeBytes: number; createdAt: string | null }>;
};
type HealthComparison = {
    id: number;
    title: string | null;
    status: string;
    beforeDate: string | null;
    afterDate: string | null;
    metrics: Array<{
        key: string;
        label: string;
        kind: 'numeric' | 'state';
        before: number | string;
        after: number | string;
        change: number | null;
        beforeDisplay: string;
        afterDisplay: string;
        changeDisplay: string | null;
        trend: string | null;
        trendLabel: string | null;
    }>;
};
type HealthMaterial = {
    id: number;
    type: string;
    filename: string;
    mimeType: string;
    sizeBytes: number;
    createdAt: string | null;
    downloadUrl: string;
    previewUrl: string | null;
};
type HealthPosturePoint = { date: string | null; summary: string[] } | null;
type Health = {
    profile: {
        available: boolean;
        updatedAt: string | null;
        anamnesis: string | null;
        complaintsGoals: string | null;
        operationsInjuries: string | null;
        medicines: string | null;
        supplements: string | null;
    };
    materials: HealthMaterial[];
    history: HealthHistoryItem[];
    comparisons: HealthComparison[];
    postureProgress: { before: HealthPosturePoint; after: HealthPosturePoint } | null;
    courseReport: { reviewedAt: string | null; summary: string | null; dynamics: string[]; currentState: string | null } | null;
    hasData: boolean;
};

const props = defineProps<{
    portal: PortalShell;
    tracker: Tracker;
    surveys: Survey;
    health: Health;
    urls: { tracker: string; surveys: string };
}>();

const { t, locale } = usePortalLocale();
const hasTests = props.surveys.definitions.length > 0 || props.surveys.attempts.length > 0;
const trackerUrl = props.urls.tracker;

const formatDate = (value: string | null): string => value
    ? new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'ru-RU', { dateStyle: 'medium' }).format(new Date(value))
    : '';

const comparisonLabel = (comparison: HealthComparison): string => {
    if (comparison.status === 'improved') {
        return t('health.comparisonImproved');
    }
    if (comparison.status === 'stagnation_detected') {
        return t('health.comparisonStagnation');
    }
    if (comparison.status === 'changed' || comparison.status === 'comparable') {
        return t('health.comparisonChanged');
    }

    return t('health.comparisonNotComparable');
};

const profileFields = computed(() => [
    { key: 'anamnesis', label: t('health.anamnesis'), value: props.health.profile.anamnesis },
    { key: 'complaintsGoals', label: t('health.complaintsGoals'), value: props.health.profile.complaintsGoals },
    { key: 'operationsInjuries', label: t('health.operationsInjuries'), value: props.health.profile.operationsInjuries },
    { key: 'medicines', label: t('health.medicines'), value: props.health.profile.medicines },
    { key: 'supplements', label: t('health.supplements'), value: props.health.profile.supplements },
].filter((field) => field.value));
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

      <section class="portal-stack">
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--card">
            {{ t('health.progress') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ t('health.progressDescription') }}
          </p>
        </div>

        <div
          v-if="props.health.comparisons.length"
          class="portal-stack portal-stack--tight"
        >
          <article
            v-for="comparison in props.health.comparisons"
            :key="comparison.id"
            class="portal-panel portal-stack portal-stack--tight min-w-0"
          >
            <div class="portal-stack portal-stack--tight min-w-0">
              <h3 class="portal-heading portal-heading--card break-words">
                {{ comparison.title || t('health.progress') }}
              </h3>
              <p class="portal-copy portal-copy--small">
                {{ comparisonLabel(comparison) }}<span v-if="comparison.beforeDate"> · {{ formatDate(comparison.beforeDate) }}</span><span v-if="comparison.afterDate"> → {{ formatDate(comparison.afterDate) }}</span>
              </p>
            </div>
            <div
              v-if="comparison.metrics.length"
              class="portal-stack portal-stack--tight"
            >
              <div
                v-for="metric in comparison.metrics"
                :key="`${comparison.id}-${metric.label}`"
                class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
              >
                <span class="portal-copy portal-copy--small break-words">{{ metric.label }}</span>
                <span class="portal-copy portal-copy--small shrink-0">
                  {{ metric.beforeDisplay }} → {{ metric.afterDisplay }}<span v-if="metric.changeDisplay !== null"> · {{ t('health.change') }}: {{ metric.changeDisplay }}</span><span v-if="metric.trendLabel"> · {{ metric.trendLabel }}</span>
                </span>
              </div>
            </div>
          </article>
        </div>
        <p
          v-else
          class="portal-copy portal-copy--small"
        >
          {{ t('health.noComparison') }}
        </p>

        <article class="portal-panel portal-stack portal-stack--tight min-w-0">
          <h3 class="portal-heading portal-heading--card">
            {{ t('health.postureProgress') }}
          </h3>
          <div
            v-if="props.health.postureProgress"
            class="grid min-w-0 gap-4 sm:grid-cols-2"
          >
            <div class="portal-stack portal-stack--tight min-w-0">
              <strong class="portal-copy--small">{{ t('health.before') }}<span v-if="props.health.postureProgress.before?.date"> · {{ formatDate(props.health.postureProgress.before.date) }}</span></strong>
              <p class="portal-copy portal-copy--small break-words">
                {{ props.health.postureProgress.before?.summary.join(' ') || t('health.noPostureProgress') }}
              </p>
            </div>
            <div class="portal-stack portal-stack--tight min-w-0">
              <strong class="portal-copy--small">{{ t('health.after') }}<span v-if="props.health.postureProgress.after?.date"> · {{ formatDate(props.health.postureProgress.after.date) }}</span></strong>
              <p class="portal-copy portal-copy--small break-words">
                {{ props.health.postureProgress.after?.summary.join(' ') || t('health.noPostureProgress') }}
              </p>
            </div>
          </div>
          <p
            v-else
            class="portal-copy portal-copy--small"
          >
            {{ t('health.noPostureProgress') }}
          </p>
        </article>

        <article class="portal-panel portal-stack portal-stack--tight min-w-0">
          <h3 class="portal-heading portal-heading--card">
            {{ t('health.courseReport') }}
          </h3>
          <div
            v-if="props.health.courseReport"
            class="portal-stack portal-stack--tight"
          >
            <p
              v-if="props.health.courseReport.reviewedAt"
              class="portal-copy portal-copy--small"
            >
              {{ t('health.courseReviewed') }} · {{ formatDate(props.health.courseReport.reviewedAt) }}
            </p>
            <p
              v-if="props.health.courseReport.summary"
              class="portal-copy break-words"
            >
              {{ props.health.courseReport.summary }}
            </p>
            <ul
              v-if="props.health.courseReport.dynamics.length"
              class="portal-list portal-list--compact"
            >
              <li
                v-for="item in props.health.courseReport.dynamics"
                :key="item"
                class="portal-list__row"
              >
                <span class="portal-copy portal-copy--small break-words">{{ item }}</span>
              </li>
            </ul>
            <p
              v-if="props.health.courseReport.currentState"
              class="portal-copy portal-copy--small break-words"
            >
              {{ props.health.courseReport.currentState }}
            </p>
          </div>
          <p
            v-else
            class="portal-copy portal-copy--small"
          >
            {{ t('health.noCourseReport') }}
          </p>
        </article>
      </section>

      <section class="portal-stack">
        <div class="portal-stack portal-stack--tight">
          <h2 class="portal-heading portal-heading--card">
            {{ t('health.history') }}
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ t('health.historyDescription') }}
          </p>
        </div>

        <div
          v-if="props.health.history.length"
          class="portal-stack portal-stack--tight"
        >
          <article
            v-for="session in props.health.history"
            :key="session.id"
            class="portal-panel portal-stack portal-stack--tight min-w-0"
          >
            <p class="portal-copy portal-copy--small">
              {{ formatDate(session.occurredAt) }}<span v-if="session.service"> · {{ session.service }}</span>
            </p>
            <p
              v-if="session.specialist"
              class="portal-copy portal-copy--small"
            >
              {{ t('health.specialist') }}: {{ session.specialist }}
            </p>
            <p
              v-if="session.result"
              class="portal-copy break-words"
            >
              <strong>{{ t('health.sessionResult') }}</strong>: {{ session.result }}
            </p>
            <p
              v-if="session.attachments.length"
              class="portal-copy portal-copy--small break-words"
            >
              {{ t('health.attachments') }}: {{ session.attachments.map((attachment) => attachment.filename).join(', ') }}
            </p>
          </article>
        </div>
        <p
          v-else
          class="portal-copy portal-copy--small"
        >
          {{ t('health.noHistory') }}
        </p>
      </section>

      <section class="portal-panel portal-stack portal-stack--tight">
        <h2 class="portal-heading portal-heading--card">
          {{ t('health.materials') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ props.health.materials.length ? t('health.materialsDescription') : t('health.noMaterials') }}
        </p>
        <p
          v-if="props.health.profile.available"
          class="portal-copy portal-copy--small"
        >
          {{ t('health.profileAvailable') }}
        </p>
        <div
          v-if="props.health.materials.length"
          class="portal-stack portal-stack--tight"
        >
          <div
            v-for="material in props.health.materials"
            :key="material.id"
            class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800"
          >
            <span class="min-w-0 break-words text-sm">{{ material.filename }}</span>
            <span class="flex shrink-0 flex-wrap gap-2 text-sm">
              <a
                v-if="material.previewUrl"
                :href="material.previewUrl"
                target="_blank"
                rel="noreferrer"
                class="portal-link"
              >{{ t('health.materialsPreview') }}</a>
              <a
                :href="material.downloadUrl"
                class="portal-link"
              >{{ t('health.materialsDownload') }}</a>
            </span>
          </div>
        </div>
        <div
          v-if="profileFields.length"
          class="portal-stack portal-stack--tight"
        >
          <div
            v-for="field in profileFields"
            :key="field.key"
            class="portal-stack portal-stack--tight min-w-0"
          >
            <strong class="portal-copy portal-copy--small">{{ field.label }}</strong>
            <p class="portal-copy break-words">
              {{ field.value }}
            </p>
          </div>
        </div>
        <p
          v-else
          class="portal-copy portal-copy--small"
        >
          {{ t('health.noProfile') }}
        </p>
      </section>
    </section>
  </AppShell>
</template>
