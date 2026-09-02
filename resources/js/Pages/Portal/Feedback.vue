<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

const props = defineProps<{
    portal: PortalShell;
    feedback: {
        enabled: boolean;
        positiveThreshold: number;
        lowScoreFeedbackRequired: boolean;
        reviewLinks: string[];
        reviewDestinations: { label: string; url: string }[];
        submitUrl: string;
    };
    result: { band: 'positive' | 'internal'; reviewLinks: string[]; reviewDestinations?: { label: string; url: string }[] } | null;
}>();

const { t } = usePortalLocale();
const form = useForm<{
    score: number | null;
    internal_feedback: string;
    idempotency_key: string;
}>({
    score: null,
    internal_feedback: '',
    idempotency_key: `portal-feedback-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`,
});

const lowScore = computed(() => form.score !== null && form.score < props.feedback.positiveThreshold);
const resultDestinations = computed(() => {
    if (props.result === null) {
        return [];
    }

    return props.result.reviewDestinations ?? props.result.reviewLinks.map((url) => ({
        label: t('feedback.reviewLinks'),
        url,
    }));
});

function selectScore(score: number): void {
    form.score = score;
    if (!lowScore.value) {
        form.internal_feedback = '';
    }
}

function submit(): void {
    form.post(props.feedback.submitUrl, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="t('feedback.title')"
    :portal="props.portal"
    active="feedback"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('feedback.title') }}
          </h1>
          <p class="portal-copy">
            {{ t('feedback.description') }}
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
        v-if="props.result"
        class="portal-panel portal-stack"
        role="status"
      >
        <h2 class="portal-heading portal-heading--section">
          {{ props.result.band === 'positive' ? t('feedback.positiveThanks') : t('feedback.internalThanks') }}
        </h2>
        <p class="portal-copy">
          {{ props.result.band === 'positive' ? t('feedback.positiveDescription') : t('feedback.internalDescription') }}
        </p>
        <div
          v-if="props.result.band === 'positive' && resultDestinations.length"
          class="portal-stack portal-stack--tight"
        >
          <span class="portal-label">{{ t('feedback.reviewLinks') }}</span>
          <a
            v-for="destination in resultDestinations"
            :key="destination.url"
            :href="destination.url"
            target="_blank"
            rel="noopener noreferrer"
            class="portal-link break-all"
          >{{ destination.label }}</a>
        </div>
      </section>

      <section
        v-if="props.feedback.enabled && !props.result"
        class="portal-panel portal-stack"
      >
        <span class="portal-label">{{ t('feedback.score') }}</span>
        <div
          class="grid grid-cols-5 gap-2 sm:grid-cols-10"
          role="radiogroup"
          :aria-label="t('feedback.score')"
        >
          <button
            v-for="score in 10"
            :key="score"
            type="button"
            class="portal-button portal-button--secondary portal-score-option min-w-0 px-2"
            :class="{ 'portal-score-option--selected': form.score === score }"
            role="radio"
            :aria-checked="form.score === score"
            :aria-pressed="form.score === score"
            @click="selectScore(score)"
          >
            {{ score }}
          </button>
        </div>
        <p
          v-if="form.errors.score"
          class="portal-copy text-[var(--portal-color-danger)]"
        >
          {{ form.errors.score }}
        </p>
        <label
          v-if="lowScore"
          class="portal-field"
        >
          <span class="portal-label">{{ t('feedback.internalFeedback') }}</span>
          <textarea
            v-model="form.internal_feedback"
            class="portal-input min-h-32"
            :required="props.feedback.lowScoreFeedbackRequired"
            maxlength="4000"
          />
          <span class="portal-copy portal-copy--small">{{ t('feedback.internalHint') }}</span>
          <span
            v-if="form.errors.internal_feedback"
            class="portal-copy text-[var(--portal-color-danger)]"
          >{{ form.errors.internal_feedback }}</span>
        </label>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          :disabled="form.processing || form.score === null"
          @click="submit"
        >
          {{ form.processing ? t('feedback.sending') : t('feedback.submit') }}
        </button>
      </section>

      <p
        v-else-if="!props.result"
        class="portal-copy"
      >
        {{ t('feedback.unavailable') }}
      </p>
    </section>
  </AppShell>
</template>
