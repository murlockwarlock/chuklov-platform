<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCalendar from '../../Components/Portal/BookingCalendar.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Specialist = { id: number; displayName: string };
type Content = { title: string; body: string; media: string | null };
type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
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
    urls: { answer: string; page: string; submit: string; login: string };
}>();

const { locale, t } = usePortalLocale();
const answerForm = useForm<{ b2b_specialist_answer: 'yes' | 'no' | null }>({
    b2b_specialist_answer: props.b2bSpecialistAnswer,
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
const leadAnswerError = computed(() => (leadForm.errors as Partial<Record<'b2b_specialist_answer', string>>).b2b_specialist_answer);
const configurationError = computed(() => (leadForm.errors as Partial<Record<'configuration', string>>).configuration);

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
    answerForm.post(props.urls.answer, { preserveScroll: true });
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

function localInputValue(value: string, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        day: '2-digit',
        hour: '2-digit',
        hourCycle: 'h23',
        minute: '2-digit',
        month: '2-digit',
        timeZone: timezone,
        year: 'numeric',
    }).formatToParts(new Date(value));
    const part = (type: string): string => parts.find((item) => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

function selectSlot(slot: AvailabilitySlot): void {
    selectedStart.value = slot.startsAt;
    leadForm.starts_at = localInputValue(slot.startsAt, slot.displayTimezone);
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
              @change="changeSpecialist"
            >
              <option
                v-if="props.specialists.length !== 1"
                :value="null"
                disabled
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
            {{ t('b2b.configurationNotReady') }}
          </p>
          <p
            v-else-if="props.b2bSpecialistAnswer !== 'yes'"
            class="portal-notice"
            role="status"
          >
            {{ t('b2b.answerRequired') }}
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
