<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Definition = { id: number; title: string; description: string | null; version: number; questionCount: number };
type Attempt = { id: number; title: string; status: 'in_progress' | 'completed'; completedAt: string | null; reportId: number | null };

const props = defineProps<{
    portal: PortalShell;
    definitions: Definition[];
    attempts: Attempt[];
    urls: { start: string };
}>();
const { t, locale } = usePortalLocale();
const startUrl = (id: number): string => props.urls.start.replace('__id__', String(id));
const formatDate = (value: string | null): string => value ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(value)) : '';
</script>

<template>
  <AppShell
    :title="t('surveys.title')"
    :portal="props.portal"
    active="health"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-stack portal-stack--tight">
        <p class="portal-eyebrow">
          {{ t('health.title') }}
        </p>
        <h1 class="portal-heading portal-heading--section">
          {{ t('surveys.title') }}
        </h1>
        <p class="portal-copy">
          {{ t('surveys.description') }}
        </p>
      </header>

      <section
        v-if="props.definitions.length"
        class="portal-stack"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('surveys.available') }}
        </h2>
        <div class="portal-stack portal-stack--tight">
          <article
            v-for="definition in props.definitions"
            :key="definition.id"
            class="portal-panel portal-panel--accent portal-stack portal-stack--tight"
          >
            <h3 class="portal-heading portal-heading--card">
              {{ definition.title }}
            </h3>
            <p
              v-if="definition.description"
              class="portal-copy portal-copy--small"
            >
              {{ definition.description }}
            </p>
            <p class="portal-copy portal-copy--small">
              {{ t('surveys.questionCount', { value: definition.questionCount }) }}
            </p>
            <Link
              :href="startUrl(definition.id)"
              method="post"
              as="button"
              class="portal-button portal-button--primary self-start"
            >
              {{ t('surveys.start') }}
            </Link>
          </article>
        </div>
      </section>

      <section
        v-if="props.attempts.length"
        class="portal-stack"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('surveys.history') }}
        </h2>
        <div class="portal-list">
          <article
            v-for="attempt in props.attempts"
            :key="attempt.id"
            class="portal-list__row"
          >
            <span>
              <strong class="portal-list__title">{{ attempt.title }}</strong>
              <span class="portal-list__summary">
                {{ attempt.status === 'completed' ? t('surveys.completed') : t('surveys.inProgress') }}<span v-if="attempt.completedAt"> · {{ formatDate(attempt.completedAt) }}</span>
              </span>
            </span>
            <Link
              :href="attempt.reportId ? `/portal/survey-reports/${attempt.reportId}` : `/portal/survey-attempts/${attempt.id}`"
              class="portal-button portal-button--secondary"
            >
              {{ attempt.reportId ? t('surveys.result') : t('surveys.resume') }}
            </Link>
          </article>
        </div>
      </section>
    </section>
  </AppShell>
</template>
