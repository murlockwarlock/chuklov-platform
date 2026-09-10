<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalIcon from '../../Components/Portal/PortalIcon.vue';
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
const sections = computed(() => props.attempt.definition.sections);
const initialAnswers = { ...props.attempt.answers } as Record<string, AnswerValue>;

for (const section of sections.value) {
    for (const question of section.questions) {
        if (question.type === 'multiple_choice' && !Array.isArray(initialAnswers[question.key])) {
            initialAnswers[question.key] = [];
        }
    }
}

const form = useForm<{ answers: Record<string, AnswerValue> }>({ answers: initialAnswers });

function visible(question: Question): boolean {
    if (!question.condition) {
        return true;
    }

    const actual = form.answers[question.condition.question_key];
    const expected = question.condition.value;

    switch (question.condition.operator) {
        case 'equals':
            return actual === expected;
        case 'not_equals':
            return actual !== expected;
        case 'in':
            return Array.isArray(expected) && expected.includes(actual);
        case 'not_in':
            return Array.isArray(expected) && !expected.includes(actual);
        case 'answered':
            return isAnswered(actual);
        case 'greater_than':
            return Number(actual) > Number(expected);
        case 'less_than':
            return Number(actual) < Number(expected);
        default:
            return false;
    }
}

function isAnswered(value: unknown): boolean {
    if (Array.isArray(value)) {
        return value.length > 0;
    }

    return value !== null && value !== undefined && value !== '';
}

function visibleQuestions(section: Section): Question[] {
    return section.questions.filter((question) => visible(question));
}

function isSectionComplete(section: Section): boolean {
    return visibleQuestions(section).every((question) => !question.required || isAnswered(form.answers[question.key]));
}

function sectionHasAnswers(section: Section): boolean {
    return visibleQuestions(section).some((question) => isAnswered(form.answers[question.key]));
}

const firstIncompleteSectionIndex = computed(() => {
    const index = sections.value.findIndex((section) => !isSectionComplete(section));

    return index === -1 ? Math.max(0, sections.value.length - 1) : index;
});
const allSectionsComplete = computed(() => sections.value.every((section) => isSectionComplete(section)));
const completedSections = computed(() => sections.value.filter((section) => isSectionComplete(section)).length);
const activeSectionIndex = ref(firstIncompleteSectionIndex.value);
const editingSectionIndex = ref<number | null>(null);
const activeSectionElement = ref<HTMLElement | null>(null);
const completionActionsElement = ref<HTMLElement | null>(null);
const saveNoticeVisible = ref(false);
let advanceTimer: number | null = null;
let saveNoticeTimer: number | null = null;

const activeSection = computed(() => sections.value[activeSectionIndex.value] ?? null);
const progress = computed(() => sections.value.length === 0
    ? 0
    : Math.round(((activeSectionIndex.value + 1) / sections.value.length) * 100));

function scrollToActiveSection(): void {
    void nextTick(() => {
        activeSectionElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

function scrollToCompletionActions(): void {
    void nextTick(() => {
        completionActionsElement.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

function clearAdvanceTimer(): void {
    if (advanceTimer !== null) {
        window.clearTimeout(advanceTimer);
        advanceTimer = null;
    }
}

function scheduleNextSection(sectionIndex: number): void {
    clearAdvanceTimer();

    if (editingSectionIndex.value !== null || sectionIndex !== activeSectionIndex.value || !isSectionComplete(sections.value[sectionIndex])) {
        return;
    }

    if (sectionIndex >= sections.value.length - 1) {
        scrollToCompletionActions();

        return;
    }

    advanceTimer = window.setTimeout(() => {
        advanceTimer = null;
        if (activeSectionIndex.value !== sectionIndex || !isSectionComplete(sections.value[sectionIndex])) {
            return;
        }

        activeSectionIndex.value = sectionIndex + 1;
        scrollToActiveSection();
    }, 220);
}

function canOpenSection(index: number): boolean {
    return index <= firstIncompleteSectionIndex.value;
}

function openSection(index: number): void {
    if (!canOpenSection(index)) {
        return;
    }

    clearAdvanceTimer();
    editingSectionIndex.value = index < activeSectionIndex.value ? index : null;
    activeSectionIndex.value = index;
    scrollToActiveSection();
}

function continueTest(): void {
    clearAdvanceTimer();
    editingSectionIndex.value = null;
    activeSectionIndex.value = firstIncompleteSectionIndex.value;
    scrollToActiveSection();
}

function selectSingleChoice(sectionIndex: number, key: string, value: string): void {
    form.answers[key] = value;
    scheduleNextSection(sectionIndex);
}

function toggleMultipleChoice(sectionIndex: number, key: string, value: string): void {
    const current = form.answers[key];
    const values = Array.isArray(current) ? current.filter((item): item is string => typeof item === 'string') : [];
    form.answers[key] = values.includes(value) ? values.filter((item) => item !== value) : [...values, value];
    scheduleNextSection(sectionIndex);
}

function updateBoolean(sectionIndex: number, key: string, event: Event): void {
    form.answers[key] = (event.target as HTMLInputElement).checked;
    scheduleNextSection(sectionIndex);
}

function scalarValue(key: string): string | number {
    const value = form.answers[key];

    return typeof value === 'string' || typeof value === 'number' ? value : '';
}

function updateScalar(sectionIndex: number, key: string, event: Event, numeric = false): void {
    const value = (event.target as HTMLInputElement | HTMLTextAreaElement).value;
    form.answers[key] = numeric && value !== '' ? Number(value) : value;
    scheduleNextSection(sectionIndex);
}

function isSelected(key: string, value: string): boolean {
    const answer = form.answers[key];

    return answer === value || (Array.isArray(answer) && answer.includes(value));
}

function optionLabel(question: Question, value: unknown): string {
    const option = question.options?.find((candidate) => candidate.value === String(value));

    return option?.label ?? (typeof value === 'string' ? value : String(value));
}

function answerLabel(question: Question): string {
    const value = form.answers[question.key];

    if (!isAnswered(value)) {
        return t('survey.noAnswer');
    }

    if (Array.isArray(value)) {
        return value.map((item) => optionLabel(question, item)).join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? t('survey.yes') : t('survey.no');
    }

    return optionLabel(question, value);
}

function save(): void {
    if (form.processing) {
        return;
    }

    form.post(props.urls.save, {
        preserveScroll: true,
        onSuccess: () => {
            saveNoticeVisible.value = true;
            if (saveNoticeTimer !== null) {
                window.clearTimeout(saveNoticeTimer);
            }
            saveNoticeTimer = window.setTimeout(() => {
                saveNoticeVisible.value = false;
                saveNoticeTimer = null;
            }, 3000);
        },
    });
}

function complete(): void {
    if (form.processing || !allSectionsComplete.value) {
        return;
    }

    form.post(props.urls.complete, { preserveScroll: false, preserveState: false });
}

onBeforeUnmount(() => {
    clearAdvanceTimer();
    if (saveNoticeTimer !== null) {
        window.clearTimeout(saveNoticeTimer);
    }
});
</script>

<template>
  <AppShell
    :title="t('surveys.title')"
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

      <header class="portal-page-heading portal-stack portal-stack--tight">
        <div class="portal-stack portal-stack--tight min-w-0">
          <p class="portal-eyebrow">
            {{ t('surveys.title') }}
          </p>
          <h1 class="portal-heading portal-heading--section break-words">
            {{ t('survey.progress') }}
          </h1>
          <p class="portal-copy portal-copy--small">
            {{ t('survey.sectionProgress', { current: activeSectionIndex + 1, total: sections.length }) }}
          </p>
        </div>
        <div class="portal-stack portal-stack--tight">
          <div class="flex min-w-0 items-center justify-between gap-3 text-sm font-semibold text-[var(--portal-color-ink-soft)]">
            <span>{{ t('survey.sectionsCompleted', { completed: completedSections, total: sections.length }) }}</span>
          </div>
          <div
            class="h-2 overflow-hidden rounded-full bg-[var(--portal-color-surface-muted)]"
            role="progressbar"
            :aria-label="t('survey.progress')"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <span
              class="block h-full rounded-full bg-[var(--portal-color-accent)] transition-[width] duration-300"
              :style="{ width: `${progress}%` }"
            />
          </div>
        </div>
      </header>

      <form
        class="portal-stack portal-stack--loose"
        @submit.prevent="complete"
      >
        <section
          v-if="activeSection"
          ref="activeSectionElement"
          class="portal-panel portal-panel--accent portal-stack"
          :aria-labelledby="`survey-section-${activeSection.key}`"
        >
          <div class="portal-stack portal-stack--tight">
            <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="portal-eyebrow">
                  {{ t('survey.sectionProgress', { current: activeSectionIndex + 1, total: sections.length }) }}
                </p>
                <h2
                  :id="`survey-section-${activeSection.key}`"
                  class="portal-heading portal-heading--section break-words"
                >
                  {{ activeSection.title }}
                </h2>
              </div>
              <span class="shrink-0 rounded-full bg-[var(--portal-color-surface)] px-3 py-1 text-xs font-semibold text-[var(--portal-color-brand-strong)]">
                {{ t('surveys.questionCount', { value: visibleQuestions(activeSection).length }) }}
              </span>
            </div>
            <p
              v-if="editingSectionIndex !== null"
              class="portal-copy portal-copy--small"
            >
              {{ t('survey.editingNotice') }}
            </p>
          </div>

          <div class="portal-stack portal-stack--loose">
            <article
              v-for="question in visibleQuestions(activeSection)"
              :key="question.key"
              class="min-w-0 portal-stack portal-stack--tight"
            >
              <div
                :id="`${question.key}-label`"
                class="flex min-w-0 flex-wrap items-baseline gap-x-1 gap-y-0.5 font-semibold text-[var(--portal-color-ink)]"
              >
                <span class="min-w-0 break-words">{{ question.label }}</span>
                <span
                  v-if="question.required"
                  class="text-[var(--portal-color-accent)]"
                  aria-hidden="true"
                >*</span>
                <span
                  v-if="question.required"
                  class="sr-only"
                >{{ t('survey.required') }}</span>
              </div>

              <div
                v-if="question.type === 'single_choice'"
                class="grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2"
                role="radiogroup"
                :aria-labelledby="`${question.key}-label`"
                :aria-required="question.required ? 'true' : 'false'"
                :aria-invalid="Boolean(form.errors[`answers.${question.key}`])"
              >
                <button
                  v-for="option in question.options"
                  :key="option.value"
                  type="button"
                  role="radio"
                  :aria-checked="isSelected(question.key, option.value)"
                  class="flex min-w-0 items-center justify-between gap-3 rounded-[var(--portal-radius-md)] border px-4 py-3 text-left text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--portal-color-accent)]"
                  :class="isSelected(question.key, option.value)
                    ? 'border-[var(--portal-color-brand-strong)] bg-[var(--portal-color-brand-strong)] text-white'
                    : 'border-[var(--portal-color-border)] bg-[var(--portal-color-surface)] text-[var(--portal-color-ink)] hover:border-[var(--portal-color-brand-strong)]'"
                  @click="selectSingleChoice(activeSectionIndex, question.key, option.value)"
                >
                  <span class="min-w-0 break-words">{{ option.label }}</span>
                  <PortalIcon
                    v-if="isSelected(question.key, option.value)"
                    name="check"
                    class="shrink-0"
                  />
                </button>
              </div>

              <div
                v-else-if="question.type === 'multiple_choice'"
                class="grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2"
                role="group"
                :aria-labelledby="`${question.key}-label`"
                :aria-invalid="Boolean(form.errors[`answers.${question.key}`])"
              >
                <label
                  v-for="option in question.options"
                  :key="option.value"
                  class="flex min-w-0 cursor-pointer items-center justify-between gap-3 rounded-[var(--portal-radius-md)] border px-4 py-3 text-sm font-semibold transition-colors focus-within:ring-2 focus-within:ring-[var(--portal-color-accent)]"
                  :class="isSelected(question.key, option.value)
                    ? 'border-[var(--portal-color-brand-strong)] bg-[var(--portal-color-brand-strong)] text-white'
                    : 'border-[var(--portal-color-border)] bg-[var(--portal-color-surface)] text-[var(--portal-color-ink)]'"
                >
                  <span class="flex min-w-0 items-center gap-3">
                    <input
                      type="checkbox"
                      class="sr-only"
                      :checked="isSelected(question.key, option.value)"
                      :value="option.value"
                      @change="toggleMultipleChoice(activeSectionIndex, question.key, option.value)"
                    >
                    <span class="min-w-0 break-words">{{ option.label }}</span>
                  </span>
                  <PortalIcon
                    v-if="isSelected(question.key, option.value)"
                    name="check"
                    class="shrink-0"
                  />
                </label>
              </div>

              <label
                v-else-if="question.type === 'boolean'"
                class="flex min-w-0 cursor-pointer items-center gap-3 rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)] bg-[var(--portal-color-surface)] px-4 py-3 font-semibold text-[var(--portal-color-ink)]"
              >
                <input
                  type="checkbox"
                  :checked="form.answers[question.key] === true"
                  @change="updateBoolean(activeSectionIndex, question.key, $event)"
                >
                <span>{{ t('survey.yes') }}</span>
              </label>

              <textarea
                v-else-if="question.type === 'long_text'"
                :id="question.key"
                :value="scalarValue(question.key)"
                rows="5"
                class="portal-input"
                :required="question.required"
                :aria-labelledby="`${question.key}-label`"
                @input="updateScalar(activeSectionIndex, question.key, $event)"
              />
              <input
                v-else
                :id="question.key"
                :value="scalarValue(question.key)"
                :type="['integer', 'number'].includes(question.type) ? 'number' : 'text'"
                class="portal-input"
                :required="question.required"
                :aria-labelledby="`${question.key}-label`"
                @input="updateScalar(activeSectionIndex, question.key, $event, ['integer', 'number'].includes(question.type))"
              >
              <p
                v-if="form.errors[`answers.${question.key}`]"
                class="portal-copy portal-copy--small text-red-700"
              >
                {{ form.errors[`answers.${question.key}`] }}
              </p>
            </article>
          </div>

          <p
            v-if="!isSectionComplete(activeSection)"
            class="portal-copy portal-copy--small"
          >
            {{ t('survey.sectionHint') }}
          </p>
          <div
            ref="completionActionsElement"
            class="portal-panel portal-stack portal-stack--tight"
          >
            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div class="portal-stack portal-stack--tight min-w-0">
                <p class="portal-copy portal-copy--small">
                  {{ allSectionsComplete ? t('survey.readyToComplete') : t('survey.completeHint') }}
                </p>
                <p
                  v-if="saveNoticeVisible"
                  class="text-sm font-semibold text-[var(--portal-color-brand-strong)]"
                  role="status"
                >
                  {{ t('survey.saved') }}
                </p>
              </div>
              <div class="flex min-w-0 flex-col gap-3 sm:flex-row">
                <button
                  type="button"
                  class="portal-button portal-button--secondary"
                  :disabled="form.processing"
                  @click="save"
                >
                  {{ t('survey.save') }}
                </button>
                <button
                  v-if="allSectionsComplete"
                  type="submit"
                  class="portal-button portal-button--primary"
                  :disabled="form.processing"
                >
                  {{ t('survey.complete') }}
                </button>
              </div>
            </div>
          </div>
          <button
            v-if="editingSectionIndex !== null"
            type="button"
            class="portal-button portal-button--secondary self-start"
            @click="continueTest"
          >
            {{ t('survey.continue') }}
          </button>
        </section>

        <section
          v-for="(section, index) in sections"
          v-show="index !== activeSectionIndex"
          :key="section.key"
          class="portal-panel portal-stack portal-stack--tight"
        >
          <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="portal-eyebrow">
                {{ t('survey.sectionProgress', { current: index + 1, total: sections.length }) }}
              </p>
              <h2 class="portal-heading portal-heading--card break-words">
                {{ section.title }}
              </h2>
            </div>
            <button
              v-if="canOpenSection(index)"
              type="button"
              class="shrink-0 text-sm font-semibold text-[var(--portal-color-brand-strong)] underline decoration-transparent underline-offset-4 transition hover:decoration-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--portal-color-accent)]"
              @click="openSection(index)"
            >
              {{ isSectionComplete(section) ? t('survey.editSection') : t('survey.continue') }}
            </button>
          </div>

          <div
            v-if="isSectionComplete(section) && visibleQuestions(section).length"
            class="divide-y divide-[var(--portal-color-border)] rounded-[var(--portal-radius-md)] border border-[var(--portal-color-border)]"
          >
            <div
              v-for="question in visibleQuestions(section)"
              :key="question.key"
              class="grid min-w-0 gap-1 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-4"
            >
              <span class="min-w-0 break-words text-sm text-[var(--portal-color-ink-soft)]">{{ question.label }}</span>
              <span class="min-w-0 break-words text-sm font-semibold text-[var(--portal-color-ink)]">{{ answerLabel(question) }}</span>
            </div>
          </div>
          <p
            v-else-if="!sectionHasAnswers(section)"
            class="portal-copy portal-copy--small"
          >
            {{ t('survey.sectionNotComplete') }}
          </p>
          <p
            v-else
            class="portal-copy portal-copy--small"
          >
            {{ t('survey.sectionInProgress') }}
          </p>
        </section>
      </form>
    </section>
  </AppShell>
</template>
