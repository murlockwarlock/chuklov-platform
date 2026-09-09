<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import EmptyState from '../../Components/Portal/EmptyState.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Access = {
    allowed: boolean;
    enabled: boolean;
    planName: string | null;
    startsAt: string | null;
    endsAt: string | null;
};
type Plan = { name: string; price: string | null; description: string | null; durationDays: number };
type Task = {
    id: number;
    title: string;
    type: 'exercise' | 'hydration' | 'practice' | 'other';
    frequency: 'daily' | 'weekly';
    weekDay: number | null;
    periodStart: string;
    status: 'pending' | 'completed' | 'not_completed';
    comment: string | null;
};
type ProgramTask = Omit<Task, 'periodStart' | 'status' | 'comment'> & { startsOn: string; endsOn: string | null };
type HistoryEntry = {
    taskId: number;
    title: string | null;
    type: string | null;
    periodStart: string;
    status: 'completed' | 'not_completed';
    comment: string | null;
    recordedAt: string;
};
type CheckIn = { occurredAt: string; note: string };
type View = 'today' | 'program' | 'history';
type Surveys = {
    definitions: Array<{ id: number; title: string; description: string | null }>;
    attempts: Array<{ id: number; title: string; status: string; reportId: number | null }>;
};

const props = defineProps<{
    portal: PortalShell;
    tracker: {
        access: Access;
        today: Task[];
        program: ProgramTask[];
        history: HistoryEntry[];
        checkIns: CheckIn[];
        monthlyPractice: string | null;
        plans: Plan[];
    };
    surveys: Surveys;
    urls: { checkIn: string; specialist: string; taskEntry: string; surveys: string };
}>();

const { t, locale } = usePortalLocale();
const activeView = ref<View>('today');
const checkInForm = useForm<{ note: string }>({ note: '' });
const taskForms = reactive<Record<number, ReturnType<typeof useForm<{ status: string; comment: string }>>>>({});
const hasTests = props.surveys.definitions.length > 0 || props.surveys.attempts.length > 0;
const formatDate = (value: string): string => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const formatDay = (value: string): string => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(value));

function taskForm(task: Task): ReturnType<typeof useForm<{ status: string; comment: string }>> {
    taskForms[task.id] ??= useForm({ status: task.status === 'pending' ? 'completed' : task.status, comment: task.comment ?? '' });

    return taskForms[task.id];
}

function taskUrl(taskId: number): string {
    return props.urls.taskEntry.replace('__id__', String(taskId));
}

function saveTask(task: Task): void {
    taskForm(task).post(taskUrl(task.id), { preserveScroll: true });
}

function setTaskStatus(task: Task, status: 'completed' | 'not_completed'): void {
    taskForm(task).status = status;
    saveTask(task);
}

function saveCheckIn(): void {
    checkInForm.post(props.urls.checkIn, { preserveScroll: true, onSuccess: () => checkInForm.reset() });
}
</script>

<template>
  <AppShell
    :title="t('tracker.title')"
    :portal="props.portal"
    active="health"
  >
    <section class="portal-container portal-container--wide portal-stack portal-stack--loose">
      <header class="portal-stack portal-stack--tight">
        <h1 class="portal-heading portal-heading--section">
          {{ t('tracker.title') }}
        </h1>
      </header>

      <template v-if="props.tracker.access.allowed">
        <nav
          class="portal-tabs portal-tabs--three"
          :aria-label="t('tracker.title')"
        >
          <button
            type="button"
            class="portal-tab"
            :class="{ 'portal-tab--active': activeView === 'today' }"
            @click="activeView = 'today'"
          >
            {{ t('tracker.today') }}
          </button>
          <button
            type="button"
            class="portal-tab"
            :class="{ 'portal-tab--active': activeView === 'program' }"
            @click="activeView = 'program'"
          >
            {{ t('tracker.program') }}
          </button>
          <button
            type="button"
            class="portal-tab"
            :class="{ 'portal-tab--active': activeView === 'history' }"
            @click="activeView = 'history'"
          >
            {{ t('tracker.history') }}
          </button>
        </nav>

        <Link
          v-if="hasTests"
          :href="props.urls.surveys"
          class="portal-list__row portal-list__row--standalone"
          data-testid="tracker-tests-link"
        >
          <span>
            <strong class="portal-list__title">{{ t('health.tests') }}</strong>
            <span class="portal-list__summary">{{ t('health.testsDescription') }}</span>
          </span>
          <span aria-hidden="true">→</span>
        </Link>

        <section
          v-if="activeView === 'today'"
          class="portal-stack"
        >
          <article
            v-if="props.tracker.monthlyPractice"
            class="portal-panel portal-panel--accent portal-stack portal-stack--tight"
          >
            <span class="portal-kicker">{{ t('tracker.monthlyPractice') }}</span>
            <p class="portal-copy whitespace-pre-wrap">
              {{ props.tracker.monthlyPractice }}
            </p>
          </article>

          <div
            v-if="props.tracker.today.length"
            class="portal-task-list"
          >
            <article
              v-for="task in props.tracker.today"
              :key="task.id"
              class="portal-task-row"
            >
              <div class="portal-task-row__content">
                <span class="portal-copy portal-copy--small">{{ t(`tracker.type.${task.type}`) }}</span>
                <h2 class="portal-heading portal-heading--card">
                  {{ task.title }}
                </h2>
                <p class="portal-copy portal-copy--small">
                  {{ task.status === 'completed' ? t('tracker.complete') : task.status === 'not_completed' ? t('tracker.notComplete') : t('tracker.pending') }}
                </p>
              </div>
              <form
                class="portal-task-row__actions"
                @submit.prevent="saveTask(task)"
              >
                <textarea
                  v-model="taskForm(task).comment"
                  class="portal-input portal-input--compact"
                  rows="2"
                  maxlength="500"
                  :placeholder="t('tracker.commentPlaceholder')"
                  :aria-label="t('tracker.comment')"
                />
                <div class="portal-action-row portal-action-row--compact">
                  <button
                    type="button"
                    class="portal-button portal-button--primary"
                    :disabled="taskForm(task).processing"
                    @click="setTaskStatus(task, 'completed')"
                  >
                    {{ t('tracker.complete') }}
                  </button>
                  <button
                    type="button"
                    class="portal-button portal-button--secondary"
                    :disabled="taskForm(task).processing"
                    @click="setTaskStatus(task, 'not_completed')"
                  >
                    {{ t('tracker.notComplete') }}
                  </button>
                </div>
              </form>
            </article>
          </div>
          <EmptyState
            v-else
            :title="t('tracker.tasksEmpty')"
          />

          <section
            class="portal-tracker-check-in portal-stack portal-stack--tight"
            aria-labelledby="tracker-check-in-heading"
          >
            <h2
              id="tracker-check-in-heading"
              class="portal-heading portal-heading--card"
            >
              {{ t('tracker.checkInTitle') }}
            </h2>
            <form
              class="portal-stack portal-stack--tight"
              @submit.prevent="saveCheckIn"
            >
              <textarea
                v-model="checkInForm.note"
                class="portal-input"
                rows="3"
                maxlength="5000"
                :placeholder="t('tracker.checkInPlaceholder')"
              />
              <button
                type="submit"
                class="portal-button portal-button--secondary self-start"
                :disabled="checkInForm.processing"
              >
                {{ checkInForm.processing ? t('tracker.saving') : t('tracker.save') }}
              </button>
            </form>
          </section>
        </section>

        <section
          v-else-if="activeView === 'program'"
          class="portal-stack"
        >
          <div
            v-if="props.tracker.program.length"
            class="portal-task-list"
          >
            <article
              v-for="task in props.tracker.program"
              :key="task.id"
              class="portal-task-row"
            >
              <div class="portal-task-row__content">
                <span class="portal-copy portal-copy--small">{{ t(`tracker.type.${task.type}`) }}</span>
                <h2 class="portal-heading portal-heading--card">
                  {{ task.title }}
                </h2>
              </div>
              <p class="portal-copy portal-copy--small portal-task-row__meta">
                {{ task.frequency === 'daily' ? t('tracker.frequencyDaily') : t('tracker.frequencyWeekly') }} · {{ formatDay(task.startsOn) }}
              </p>
            </article>
          </div>
          <EmptyState
            v-else
            :title="t('tracker.programEmpty')"
          />
        </section>

        <section
          v-else
          class="portal-stack"
        >
          <div
            v-if="props.tracker.history.length"
            class="portal-task-list"
          >
            <article
              v-for="entry in props.tracker.history"
              :key="`${entry.taskId}-${entry.periodStart}`"
              class="portal-task-row"
            >
              <div class="portal-task-row__content">
                <span class="portal-copy portal-copy--small">{{ formatDay(entry.periodStart) }}</span>
                <h2 class="portal-heading portal-heading--card">
                  {{ entry.title }}
                </h2>
                <p class="portal-copy portal-copy--small">
                  {{ entry.status === 'completed' ? t('tracker.complete') : t('tracker.notComplete') }}<span v-if="entry.comment"> · {{ entry.comment }}</span>
                </p>
              </div>
              <time class="portal-copy portal-copy--small portal-task-row__meta">{{ formatDate(entry.recordedAt) }}</time>
            </article>
          </div>
          <EmptyState
            v-else
            :title="t('tracker.emptyHistory')"
          />
          <section
            v-if="props.tracker.checkIns.length"
            class="portal-tracker-history-notes portal-stack portal-stack--tight"
          >
            <h2 class="portal-heading portal-heading--card">
              {{ t('tracker.checkInTitle') }}
            </h2>
            <article
              v-for="entry in props.tracker.checkIns"
              :key="entry.occurredAt"
              class="portal-tracker-history-note portal-stack portal-stack--tight"
            >
              <time class="portal-copy portal-copy--small">{{ formatDate(entry.occurredAt) }}</time>
              <p class="portal-copy whitespace-pre-wrap">
                {{ entry.note }}
              </p>
            </article>
          </section>
        </section>

        <Link
          :href="props.urls.specialist"
          class="portal-link portal-tracker-specialist-link"
          data-testid="tracker-specialist-cta"
        >
          {{ t('tracker.discuss') }}
        </Link>
      </template>

      <section
        v-else
        class="portal-panel portal-panel--accent portal-stack portal-stack--tight"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('tracker.title') }}
        </h2>
        <p class="portal-copy">
          {{ t('tracker.unavailable') }}
        </p>
        <div
          v-if="props.tracker.plans.length"
          class="portal-list"
        >
          <div
            v-for="plan in props.tracker.plans"
            :key="plan.name"
            class="portal-list__row"
          >
            <span>
              <strong class="portal-list__title">{{ plan.name }}</strong>
              <span
                v-if="plan.description"
                class="portal-list__summary"
              >{{ plan.description }}</span>
            </span>
            <span class="portal-copy portal-copy--small">{{ plan.price }} · {{ plan.durationDays }} {{ t('tracker.days') }}</span>
          </div>
        </div>
      </section>
    </section>
  </AppShell>
</template>
