<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Specialist = { id: number; displayName: string };
type Content = { title: string; body: string; media: string | null };

const props = defineProps<{
    portal: PortalShell;
    authenticated: boolean;
    b2bSpecialistAnswer: 'yes' | 'no' | null;
    content: Content[];
    specialists: Specialist[];
    urls: { answer: string; submit: string; login: string };
}>();

const { t } = usePortalLocale();
const answerForm = useForm<{ b2b_specialist_answer: 'yes' | 'no' | null }>({
    b2b_specialist_answer: props.b2bSpecialistAnswer,
});
const leadForm = useForm<{
    specialist_id: number | null;
    starts_at: string;
    submission_key: string;
}>({
    specialist_id: props.specialists[0]?.id ?? null,
    starts_at: '',
    submission_key: crypto.randomUUID(),
});
const leadAnswerError = computed(() => (leadForm.errors as Partial<Record<'b2b_specialist_answer', string>>).b2b_specialist_answer);

function saveAnswer(): void {
    answerForm.post(props.urls.answer, { preserveScroll: true });
}

function submitLead(): void {
    leadForm.post(props.urls.submit, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="t('b2b.title')"
    :portal="props.portal"
    active="b2b"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-page-heading">
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            CHUKLOV
          </p>
          <h1 class="portal-heading portal-heading--section">
            {{ t('b2b.title') }}
          </h1>
        </div>
      </header>

      <article
        v-for="item in props.content"
        :key="item.title"
        class="portal-panel portal-stack"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ item.title }}
        </h2>
        <p class="portal-copy">
          {{ item.body }}
        </p>
      </article>

      <section class="portal-panel portal-stack">
        <h2 class="portal-heading portal-heading--card">
          {{ t('b2b.questionTitle') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ t('b2b.question') }}
        </p>
        <form
          class="portal-stack"
          @submit.prevent="saveAnswer"
        >
          <label class="portal-confirm">
            <input
              v-model="answerForm.b2b_specialist_answer"
              type="radio"
              value="yes"
              class="portal-checkbox"
            >
            <span>{{ t('b2b.yes') }}</span>
          </label>
          <label class="portal-confirm">
            <input
              v-model="answerForm.b2b_specialist_answer"
              type="radio"
              value="no"
              class="portal-checkbox"
            >
            <span>{{ t('b2b.no') }}</span>
          </label>
          <button
            type="submit"
            class="portal-button portal-button--secondary self-start"
            :disabled="answerForm.processing"
          >
            {{ answerForm.processing ? t('profile.saving') : t('profile.save') }}
          </button>
        </form>
      </section>

      <section
        v-if="props.authenticated"
        class="portal-panel portal-stack"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('b2b.requestTitle') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ t('b2b.requestDescription') }}
        </p>
        <form
          class="portal-stack"
          @submit.prevent="submitLead"
        >
          <div class="portal-field">
            <label
              for="b2b-specialist"
              class="portal-label"
            >{{ t('b2b.specialist') }}</label>
            <select
              id="b2b-specialist"
              v-model="leadForm.specialist_id"
              class="portal-input"
              required
            >
              <option
                v-for="specialist in props.specialists"
                :key="specialist.id"
                :value="specialist.id"
              >
                {{ specialist.displayName }}
              </option>
            </select>
          </div>
          <div class="portal-field">
            <label
              for="b2b-starts-at"
              class="portal-label"
            >{{ t('b2b.dateTime') }}</label>
            <input
              id="b2b-starts-at"
              v-model="leadForm.starts_at"
              type="datetime-local"
              class="portal-input"
              required
            >
          </div>
          <p
            v-if="leadForm.errors.starts_at || leadAnswerError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ leadForm.errors.starts_at ?? leadAnswerError }}
          </p>
          <button
            type="submit"
            class="portal-button portal-button--primary self-start"
            :disabled="leadForm.processing || props.specialists.length === 0"
          >
            {{ leadForm.processing ? t('b2b.submitting') : t('b2b.submit') }}
          </button>
        </form>
      </section>

      <section
        v-else
        class="portal-panel portal-stack"
      >
        <p class="portal-copy">
          {{ t('b2b.loginRequired') }}
        </p>
        <Link
          :href="props.urls.login"
          class="portal-button portal-button--primary self-start"
        >
          {{ t('entry.telegram') }}
        </Link>
      </section>
    </section>
  </AppShell>
</template>
