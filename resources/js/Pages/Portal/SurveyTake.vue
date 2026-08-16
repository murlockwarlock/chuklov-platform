<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AppShell from '../../Components/Portal/AppShell.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Option = { value: string; label: string };
type Condition = { question_key: string; operator: string; value?: unknown };
type Question = { key: string; type: string; label: string; required?: boolean; options?: Option[]; condition?: Condition };
type Section = { key: string; title: string; questions: Question[] };
type AnswerValue = string | number | boolean | string[] | null;

const props = defineProps<{
    portal: PortalShell;
    attempt: { id: number; definition: { sections: Section[] }; answers: Record<string, unknown> };
    urls: { index: string; save: string; complete: string };
}>();
const { t } = usePortalLocale();
const initialAnswers = { ...props.attempt.answers } as Record<string, AnswerValue>;
for (const section of props.attempt.definition.sections) {
    for (const question of section.questions) {
        if (question.type === 'multiple_choice' && !Array.isArray(initialAnswers[question.key])) {
            initialAnswers[question.key] = [];
        }
    }
}
const form = useForm<{ answers: Record<string, AnswerValue> }>({ answers: initialAnswers });

function visible(question: Question): boolean {
    if (!question.condition) return true;
    const actual = form.answers[question.condition.question_key];
    const expected = question.condition.value;
    switch (question.condition.operator) {
        case 'equals': return actual === expected;
        case 'not_equals': return actual !== expected;
        case 'in': return Array.isArray(expected) && expected.includes(actual);
        case 'not_in': return Array.isArray(expected) && !expected.includes(actual);
        case 'answered': return actual !== null && actual !== undefined && actual !== '' && (!Array.isArray(actual) || actual.length > 0);
        case 'greater_than': return Number(actual) > Number(expected);
        case 'less_than': return Number(actual) < Number(expected);
        default: return false;
    }
}

function submit(url: string): void {
    form.post(url, { preserveScroll: true });
}

function scalarValue(key: string): string | number {
    const value = form.answers[key];
    return typeof value === 'string' || typeof value === 'number' ? value : '';
}

function updateScalar(key: string, event: Event, numeric = false): void {
    const value = (event.target as HTMLInputElement | HTMLTextAreaElement).value;
    form.answers[key] = numeric && value !== '' ? Number(value) : value;
}
</script>

<template>
  <AppShell
    :title="t('surveys.title')"
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
      <form
        class="portal-stack portal-stack--loose"
        @submit.prevent="submit(props.urls.complete)"
      >
        <section
          v-for="section in props.attempt.definition.sections"
          :key="section.key"
          class="portal-panel portal-stack"
        >
          <h2 class="portal-heading portal-heading--section">
            {{ section.title }}
          </h2>
          <div
            v-for="question in section.questions"
            v-show="visible(question)"
            :key="question.key"
            class="portal-stack portal-stack--tight"
          >
            <label
              :for="question.key"
              class="font-semibold text-[var(--portal-color-ink)]"
            >
              {{ question.label }}
              <span
                v-if="question.required"
                class="text-[var(--portal-color-accent)]"
              >*</span>
            </label>
            <select
              v-if="question.type === 'single_choice'"
              :id="question.key"
              v-model="form.answers[question.key]"
              class="portal-input"
            >
              <option
                value=""
                disabled
              >
                —
              </option>
              <option
                v-for="option in question.options"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
            <div
              v-else-if="question.type === 'multiple_choice'"
              class="grid gap-2"
            >
              <label
                v-for="option in question.options"
                :key="option.value"
                class="flex items-center gap-2"
              >
                <input
                  v-model="form.answers[question.key]"
                  type="checkbox"
                  :value="option.value"
                >
                <span>{{ option.label }}</span>
              </label>
            </div>
            <label
              v-else-if="question.type === 'boolean'"
              class="flex items-center gap-2"
            >
              <input
                v-model="form.answers[question.key]"
                type="checkbox"
              >
              <span>{{ question.label }}</span>
            </label>
            <textarea
              v-else-if="question.type === 'long_text'"
              :id="question.key"
              :value="scalarValue(question.key)"
              rows="5"
              class="portal-input"
              @input="updateScalar(question.key, $event)"
            />
            <input
              v-else
              :id="question.key"
              :value="scalarValue(question.key)"
              :type="['integer', 'number'].includes(question.type) ? 'number' : 'text'"
              class="portal-input"
              @input="updateScalar(question.key, $event, ['integer', 'number'].includes(question.type))"
            >
            <p
              v-if="form.errors[`answers.${question.key}`]"
              class="portal-copy portal-copy--small text-red-700"
            >
              {{ form.errors[`answers.${question.key}`] }}
            </p>
          </div>
        </section>
        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
          <button
            type="button"
            class="portal-button portal-button--secondary"
            :disabled="form.processing"
            @click="submit(props.urls.save)"
          >
            {{ t('survey.save') }}
          </button>
          <button
            type="submit"
            class="portal-button portal-button--primary"
            :disabled="form.processing"
          >
            {{ t('survey.complete') }}
          </button>
        </div>
      </form>
    </section>
  </AppShell>
</template>
