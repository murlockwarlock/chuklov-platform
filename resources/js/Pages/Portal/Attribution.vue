<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{
    portal: PortalShell;
    needsManualSource: boolean;
    sources: string[];
}>();

const { t } = usePortalLocale();
const form = useForm<{ source: string; source_detail: string }>({ source: '', source_detail: '' });

const labels: Record<string, string> = {
    friend: 'attribution.friend',
    social: 'attribution.social',
    search: 'attribution.search',
    partner: 'attribution.partner',
    other: 'attribution.other',
};

function submit(): void {
    if (! ['friend', 'other'].includes(form.source)) form.source_detail = '';
    form.post(props.portal.urls.attribution, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="t('attribution.title')"
    :portal="props.portal"
    active="attribution"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('attribution.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('attribution.description') }}
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
        v-if="props.needsManualSource"
        class="portal-panel portal-stack"
      >
        <form
          class="portal-stack"
          @submit.prevent="submit"
        >
          <label class="portal-field">
            <span class="portal-label">{{ t('attribution.source') }}</span>
            <select
              v-model="form.source"
              class="portal-input portal-select"
              required
              :aria-invalid="Boolean(form.errors.source)"
            >
              <option
                value=""
                disabled
              >{{ t('attribution.choose') }}</option>
              <option
                v-for="source in props.sources"
                :key="source"
                :value="source"
              >{{ t(labels[source] ?? 'attribution.other') }}</option>
            </select>
            <span
              v-if="form.errors.source"
              class="portal-copy text-[var(--portal-color-danger)]"
            >{{ form.errors.source }}</span>
          </label>
          <label
            v-if="['friend', 'other'].includes(form.source)"
            class="portal-field"
          >
            <span class="portal-label">{{ t('attribution.detail') }}</span>
            <textarea
              v-model="form.source_detail"
              class="portal-input"
              rows="3"
              maxlength="500"
              :placeholder="t('attribution.detailHint')"
              :aria-invalid="Boolean(form.errors.source_detail)"
            />
            <span
              v-if="form.errors.source_detail"
              class="portal-copy text-[var(--portal-color-danger)]"
            >{{ form.errors.source_detail }}</span>
          </label>
          <button
            type="submit"
            class="portal-button portal-button--primary self-start"
            :disabled="form.processing"
          >
            {{ form.processing ? t('attribution.saving') : t('attribution.save') }}
          </button>
        </form>
      </section>
      <p
        v-else
        class="portal-copy"
      >
        {{ t('attribution.accepted') }}
      </p>
    </section>
  </AppShell>
</template>
