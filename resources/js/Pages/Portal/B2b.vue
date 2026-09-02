<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCalendar from '../../Components/Portal/BookingCalendar.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Specialist = { id: number; displayName: string };
type Content = { title: string; body: string; media: string | null };
type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayUtcOffset: string;
    displayTimezone: string;
    format: 'office' | 'home' | 'online';
};
type Availability = {
    specialistId: number;
    serviceId: number | null;
    scheduleTimezone: string;
    displayTimezone: string;
    slots: AvailabilitySlot[];
};
type AvailabilityRange = { dateFrom: string; dateTo: string };
type CurrentRequest = {
    leadId: number;
    salesCallId: number;
    startsAt: string;
    endsAt: string;
    requestedTimezone: string;
    specialistName: string;
    meetingMode: 'automatic' | 'manual';
    meetingStatus: 'ready' | 'automatic_pending' | 'manual_pending' | 'needs_sync';
    meetingUrl: string | null;
};

const props = defineProps<{
    portal: PortalShell;
    authenticated: boolean;
    b2bSpecialistAnswer: 'yes' | 'no' | null;
    content: Content[];
    specialists: Specialist[];
    selectedSpecialistId: number | null;
    availability: Availability | null;
    availabilityRange: AvailabilityRange | null;
    configurationReady: boolean;
    configurationIssue: 'missing_duration' | 'zoom_duration_exceeds_capability' | null;
    currentRequest: CurrentRequest | null;
    urls: { answer: string; page: string; submit: string; login: string };
}>();

const { locale, t } = usePortalLocale();
const answerForm = useForm<{
    b2b_specialist_answer: 'yes' | 'no' | null;
    return_to: 'b2b';
}>({
    b2b_specialist_answer: props.b2bSpecialistAnswer,
    return_to: 'b2b',
});
const leadForm = useForm<{
    specialist_id: number | null;
    starts_at: string;
    submission_key: string;
}>({
    specialist_id: props.selectedSpecialistId,
    starts_at: '',
    submission_key: crypto.randomUUID(),
});
const selectedStart = ref<string | null>(null);
const selectedDate = ref<string | null>(null);
const editingAnswer = ref(props.b2bSpecialistAnswer === null);
const leadAnswerError = computed(() => (leadForm.errors as Partial<Record<'b2b_specialist_answer', string>>).b2b_specialist_answer);
const configurationError = computed(() => (leadForm.errors as Partial<Record<'configuration', string>>).configuration);
const meetingReloading = ref(false);
let meetingPollTimer: ReturnType<typeof setInterval> | null = null;

function stopMeetingPolling(): void {
    if (meetingPollTimer === null) {
        return;
    }

    window.clearInterval(meetingPollTimer);
    meetingPollTimer = null;
}

function refreshPendingMeeting(): void {
    if (props.currentRequest?.meetingStatus !== 'automatic_pending') {
        stopMeetingPolling();

        return;
    }

    if (meetingReloading.value) {
        return;
    }

    meetingReloading.value = true;
    router.reload({
        only: ['currentRequest'],
        onFinish: () => {
            meetingReloading.value = false;
        },
    });
}

function syncMeetingPolling(): void {
    if (props.currentRequest?.meetingStatus !== 'automatic_pending') {
        stopMeetingPolling();

        return;
    }

    if (meetingPollTimer === null) {
        meetingPollTimer = window.setInterval(refreshPendingMeeting, 5000);
    }
}

onMounted(syncMeetingPolling);
onBeforeUnmount(stopMeetingPolling);

watch(
    () => props.currentRequest?.meetingStatus,
    syncMeetingPolling,
);

watch(
    () => [props.selectedSpecialistId, props.availabilityRange?.dateFrom] as const,
    ([specialistId]) => {
        leadForm.specialist_id = specialistId;
        selectedDate.value = null;
        selectedStart.value = null;
        leadForm.starts_at = '';
    },
    { immediate: true },
);

function saveAnswer(): void {
    answerForm.post(props.urls.answer, {
        preserveScroll: true,
        onSuccess: () => {
            editingAnswer.value = false;
        },
    });
}

function editAnswer(): void {
    answerForm.b2b_specialist_answer = props.b2bSpecialistAnswer;
    editingAnswer.value = true;
}

function changeSpecialist(): void {
    router.get(props.urls.page, {
        specialist_id: leadForm.specialist_id,
        date_from: props.availabilityRange?.dateFrom,
    }, { preserveScroll: true, preserveState: false });
}

function changeMonth(dateFrom: string): void {
    router.get(props.urls.page, {
        specialist_id: props.selectedSpecialistId,
        date_from: dateFrom,
    }, { preserveScroll: true, preserveState: false });
}

function selectDate(date: string): void {
    selectedDate.value = date;
    selectedStart.value = null;
    leadForm.starts_at = '';
}

function selectSlot(slot: AvailabilitySlot): void {
    selectedStart.value = slot.startsAt;
    leadForm.starts_at = slot.startsAt;
}

function submitLead(): void {
    leadForm.post(props.urls.submit, { preserveScroll: true });
}

function requestDate(value: string, timezone: string): string {
    return new Intl.DateTimeFormat(locale.value === 'ru' ? 'ru-RU' : 'en-US', {
        day: 'numeric',
        month: 'long',
        timeZone: timezone,
    }).format(new Date(value));
}

function requestTime(value: string, timezone: string): string {
    return new Intl.DateTimeFormat(locale.value === 'ru' ? 'ru-RU' : 'en-US', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    }).format(new Date(value));
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

      <section
        v-if="props.authenticated && !editingAnswer"
        class="portal-panel portal-stack portal-stack--tight"
      >
        <div class="portal-section-heading">
          <div class="portal-stack portal-stack--tight">
            <h2 class="portal-heading portal-heading--card">
              {{ t('b2b.profileTitle') }}
            </h2>
            <p class="portal-copy portal-copy--small">
              {{ props.b2bSpecialistAnswer === 'yes' ? t('b2b.answerYesSummary') : t('b2b.answerNoSummary') }}
            </p>
          </div>
          <button
            type="button"
            class="portal-link portal-link--button"
            @click="editAnswer"
          >
            {{ t('b2b.editAnswer') }}
          </button>
        </div>
      </section>

      <section
        v-if="props.authenticated && editingAnswer"
        class="portal-panel portal-stack"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('b2b.questionTitle') }}
        </h2>
        <p class="portal-copy portal-copy--small">
          {{ t('b2b.question') }}
        </p>
        <form
          class="portal-stack portal-stack--tight"
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
          <p
            v-if="answerForm.errors.b2b_specialist_answer"
            class="portal-error"
          >
            {{ answerForm.errors.b2b_specialist_answer }}
          </p>
          <button
            type="submit"
            class="portal-button portal-button--secondary self-start"
            :disabled="answerForm.processing || answerForm.b2b_specialist_answer === null"
          >
            {{ answerForm.processing ? t('profile.saving') : t('profile.save') }}
          </button>
        </form>
      </section>

      <section
        v-if="props.authenticated && props.currentRequest"
        class="portal-panel portal-stack"
        role="status"
      >
        <div class="portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ t('b2b.requestAccepted') }}
          </p>
          <h2 class="portal-heading portal-heading--card">
            {{ t('b2b.conversationScheduled') }}
          </h2>
          <p class="portal-copy">
            {{ requestDate(props.currentRequest.startsAt, props.currentRequest.requestedTimezone) }},
            {{ requestTime(props.currentRequest.startsAt, props.currentRequest.requestedTimezone) }}
            · {{ props.currentRequest.specialistName }}
          </p>
          <p class="portal-copy portal-copy--small">
            {{ props.currentRequest.requestedTimezone }}
          </p>
        </div>
        <p
          v-if="props.currentRequest.meetingStatus === 'automatic_pending'"
          class="portal-notice"
        >
          {{ t('b2b.automaticPending') }}
        </p>
        <p
          v-else-if="props.currentRequest.meetingStatus === 'manual_pending'"
          class="portal-notice"
        >
          {{ t('b2b.manualPending') }}
        </p>
        <p
          v-else-if="props.currentRequest.meetingStatus === 'needs_sync'"
          class="portal-notice portal-notice--error"
        >
          {{ t('b2b.needsSync') }}
        </p>
        <a
          v-else-if="props.currentRequest.meetingUrl"
          :href="props.currentRequest.meetingUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="portal-button portal-button--primary self-start"
        >
          {{ t('b2b.joinConversation') }}
        </a>
      </section>

      <section
        v-else-if="props.authenticated && props.b2bSpecialistAnswer === 'yes' && !editingAnswer"
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
              class="portal-input portal-select"
              required
              :aria-invalid="Boolean(leadForm.errors.specialist_id)"
              @change="changeSpecialist"
            >
              <option
                v-if="props.specialists.length !== 1"
                :value="null"
                disabled
                :selected="leadForm.specialist_id === null"
              >
                {{ t('b2b.chooseSpecialist') }}
              </option>
              <option
                v-for="specialist in props.specialists"
                :key="specialist.id"
                :value="specialist.id"
              >
                {{ specialist.displayName }}
              </option>
            </select>
          </div>
          <p
            v-if="!props.configurationReady"
            class="portal-notice"
            role="status"
          >
            {{ props.configurationIssue === 'zoom_duration_exceeds_capability' ? t('b2b.zoomDurationNotSupported') : t('b2b.configurationNotReady') }}
          </p>
          <BookingCalendar
            v-else-if="props.availability && props.availabilityRange && props.selectedSpecialistId !== null"
            :availability="props.availability"
            :date-from="props.availabilityRange.dateFrom"
            :date-to="props.availabilityRange.dateTo"
            :locale="locale"
            :selected-date="selectedDate"
            :selected-start="selectedStart"
            @select-date="selectDate"
            @select-slot="selectSlot"
            @change-month="changeMonth"
          />
          <p
            v-else
            class="portal-notice"
            role="status"
          >
            {{ t('b2b.noAvailableSlots') }}
          </p>
          <p
            v-if="leadForm.errors.starts_at || configurationError || leadAnswerError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ leadForm.errors.starts_at ?? configurationError ?? leadAnswerError }}
          </p>
          <button
            type="submit"
            class="portal-button portal-button--primary self-start"
            :disabled="leadForm.processing || props.specialists.length === 0 || props.selectedSpecialistId === null || selectedStart === null"
          >
            {{ leadForm.processing ? t('b2b.submitting') : t('b2b.submit') }}
          </button>
        </form>
      </section>

      <section
        v-else-if="props.authenticated && props.b2bSpecialistAnswer === 'no'"
        class="portal-panel portal-stack portal-stack--tight"
      >
        <h2 class="portal-heading portal-heading--card">
          {{ t('b2b.nextTitle') }}
        </h2>
        <p class="portal-copy">
          {{ t('b2b.noNextStep') }}
        </p>
      </section>

      <section
        v-if="!props.authenticated"
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
