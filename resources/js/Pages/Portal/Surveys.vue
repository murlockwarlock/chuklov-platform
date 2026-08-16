<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Definition = { id: number; title: string; description: string | null; version: number };
type Attempt = { id: number; title: string; version: number; status: 'in_progress' | 'completed'; completedAt: string | null; reportId: number | null };

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
    active="surveys"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('surveys.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('surveys.description') }}
          </p>
        </div>
      </header>

      <section class="portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('surveys.available') }}
        </h2>
        <EmptyState
          v-if="!props.definitions.length"
          :title="t('surveys.empty')"
        />
        <div
          v-else
          class="grid grid-cols-1 gap-4 md:grid-cols-2"
        >
          <article
            v-for="definition in props.definitions"
            :key="definition.id"
            class="portal-panel portal-stack portal-stack--tight"
          >
            <div>
              <h3 class="portal-heading portal-heading--section">
                {{ definition.title }}
              </h3>
              <p
                v-if="definition.description"
                class="portal-copy portal-copy--small"
              >
                {{ definition.description }}
              </p>
            </div>
            <p class="portal-copy portal-copy--small">
              {{ t('surveys.version', { value: definition.version }) }}
            </p>
            <Link
              :href="startUrl(definition.id)"
              method="post"
              as="button"
              class="portal-button portal-button--primary"
            >
              {{ t('surveys.start') }}
            </Link>
          </article>
        </div>
      </section>

      <section class="portal-stack">
        <h2 class="portal-heading portal-heading--section">
          {{ t('surveys.history') }}
        </h2>
        <EmptyState
          v-if="!props.attempts.length"
          :title="t('surveys.noHistory')"
        />
        <div
          v-else
          class="portal-panel divide-y divide-[var(--portal-color-border)]"
        >
          <article
            v-for="attempt in props.attempts"
            :key="attempt.id"
            class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <h3 class="font-semibold text-[var(--portal-color-ink)]">
                {{ attempt.title }}
              </h3>
              <p class="portal-copy portal-copy--small">
                {{ attempt.status === 'completed' ? t('surveys.completed') : t('surveys.inProgress') }}
                <span v-if="attempt.completedAt"> · {{ formatDate(attempt.completedAt) }}</span>
              </p>
            </div>
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
