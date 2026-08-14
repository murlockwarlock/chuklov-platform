<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PortalDateTime from '../../Components/PortalDateTime.vue';

type Slot = {
    startsAt: string;
    endsAt: string;
    displayStartsAt: string;
    displayEndsAt: string;
};

type Booking = {
    id: number;
    service: { name: string };
    specialist: { displayName: string };
    startsAt: string;
    endsAt: string;
    localDate: string;
    localTime: string;
    localEndsAt: string;
    timezone: string;
    formatLabel: string;
    statusLabel: string;
    paymentStatusLabel: string;
    location: string | null;
    meetingUrl: string | null;
    partySize: number;
    eventVersion: number;
    canCancel: boolean;
    canReschedule: boolean;
    contactStaff: boolean;
    pendingReview: boolean;
    history: { label: string; oldStartsAt: string | null; newStartsAt: string | null; occurredAt: string }[];
};

const props = defineProps<{
    booking: Booking;
    availability: { displayTimezone: string; slots: Slot[] } | null;
    client: { timezone: string };
    urls: { index: string; cancel: string; reschedule: string; timezone: string; services: string };
}>();

const selectedSlot = ref<string | null>(null);
const cancelForm = useForm<{ reason: string | null }>({ reason: null });
const rescheduleForm = useForm<{ starts_at: string | null; client_timezone: string; reason: string | null; expected_event_version: number }>({
    starts_at: null,
    client_timezone: props.client.timezone,
    reason: null,
    expected_event_version: props.booking.eventVersion,
});
const timezoneForm = useForm<{ timezone: string }>({ timezone: props.client.timezone });
const rescheduleError = computed(() => {
    const errors = rescheduleForm.errors as Record<string, string | undefined>;

    return errors.starts_at
        ?? errors.startsAt
        ?? errors.booking
        ?? errors.expected_event_version;
});
const cancelError = computed(() => (cancelForm.errors as Record<string, string | undefined>).booking);

const timezoneLabels: Record<string, string> = {
    UTC: 'Всемирное время',
    'Asia/Almaty': 'Алматы',
    'Asia/Aqtau': 'Актау',
    'Asia/Atyrau': 'Атырау',
    'Asia/Aqtobe': 'Актобе',
    'Asia/Tashkent': 'Ташкент',
    'Asia/Dubai': 'Дубай',
    'Europe/Moscow': 'Москва',
    'Europe/Berlin': 'Берлин',
    'Europe/London': 'Лондон',
    'America/New_York': 'Нью-Йорк',
    'America/Los_Angeles': 'Лос-Анджелес',
};

const timezoneOptions = computed(() => Array.from(new Set([
    props.client.timezone,
    'Asia/Almaty',
    'Europe/Moscow',
    'Europe/Berlin',
    'UTC',
])).map((value) => ({
    value,
    label: timezoneLabels[value] ?? 'Текущий часовой пояс',
})));

watch(
    () => props.booking.eventVersion,
    (eventVersion, previousEventVersion) => {
        rescheduleForm.expected_event_version = eventVersion;

        if (previousEventVersion !== undefined && eventVersion !== previousEventVersion) {
            selectedSlot.value = null;
            rescheduleForm.starts_at = null;
        }
    },
    { immediate: true },
);

watch(
    () => props.client.timezone,
    (timezone) => {
        timezoneForm.timezone = timezone;
        rescheduleForm.client_timezone = timezone;
    },
    { immediate: true },
);

function selectSlot(slot: Slot): void {
    selectedSlot.value = slot.startsAt;
    rescheduleForm.starts_at = slot.startsAt;
}

function cancelBooking(): void {
    cancelForm.post(props.urls.cancel, { preserveScroll: true });
}

function rescheduleBooking(): void {
    if (selectedSlot.value === null) {
        return;
    }

    rescheduleForm.post(props.urls.reschedule, { preserveScroll: true });
}

function saveTimezone(): void {
    timezoneForm.post(props.urls.timezone, { preserveScroll: true });
}
</script>

<template>
  <Head title="Детали записи" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ props.booking.statusLabel }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            {{ props.booking.service.name }}
          </h1>
          <p class="portal-lede">
            {{ props.booking.specialist.displayName }} · {{ props.booking.formatLabel }}
          </p>
        </div>
      </header>

      <section
        class="portal-panel portal-stack portal-stack--tight"
        aria-labelledby="booking-time-heading"
      >
        <h2
          id="booking-time-heading"
          class="portal-heading portal-heading--section"
        >
          Дата и время
        </h2>
        <p class="portal-heading portal-heading--card">
          <PortalDateTime
            :value="props.booking.startsAt"
            :time-zone="props.booking.timezone"
          />
        </p>
        <p class="portal-copy portal-copy--small">
          До {{ props.booking.localEndsAt }}
        </p>
        <p class="portal-copy portal-copy--small">
          Оплата: {{ props.booking.paymentStatusLabel }}
        </p>
        <p
          v-if="props.booking.location"
          class="portal-copy"
        >
          {{ props.booking.location }}
        </p>
        <a
          v-if="props.booking.meetingUrl"
          :href="props.booking.meetingUrl"
          rel="noopener noreferrer"
          target="_blank"
          class="portal-link"
        >
          Открыть ссылку на встречу
        </a>
        <p
          v-if="props.booking.pendingReview"
          class="portal-notice"
        >
          Заявка на выезд отправлена. Мы подтвердим время отдельно.
        </p>
        <p
          v-if="props.booking.contactStaff"
          class="portal-notice"
        >
          Перенести запись онлайн уже нельзя. Свяжитесь с нами.
        </p>
      </section>

      <section
        v-if="props.booking.canReschedule && props.availability"
        class="portal-stack"
        aria-labelledby="reschedule-heading"
      >
        <h2
          id="reschedule-heading"
          class="portal-heading portal-heading--section"
        >
          Перенести запись
        </h2>
        <p
          v-if="props.availability.slots.length === 0"
          class="portal-notice"
        >
          Другого свободного времени пока нет.
        </p>
        <div
          v-else
          class="portal-grid portal-grid--cards"
          aria-label="Свободное время для переноса"
        >
          <button
            v-for="slot in props.availability.slots"
            :key="slot.startsAt"
            type="button"
            class="portal-card portal-card--interactive"
            :aria-pressed="selectedSlot === slot.startsAt"
            @click="selectSlot(slot)"
          >
            <PortalDateTime
              :value="slot.startsAt"
              :time-zone="props.availability.displayTimezone"
            />
          </button>
        </div>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          :disabled="rescheduleForm.processing || selectedSlot === null"
          @click="rescheduleBooking"
        >
          {{ rescheduleForm.processing ? 'Сохраняем…' : 'Перенести запись' }}
        </button>
        <p
          v-if="rescheduleError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ rescheduleError }}
        </p>
      </section>

      <section
        v-if="props.booking.canCancel"
        class="portal-panel portal-stack portal-stack--tight"
        aria-labelledby="cancel-heading"
      >
        <h2
          id="cancel-heading"
          class="portal-heading portal-heading--section"
        >
          Отмена
        </h2>
        <button
          type="button"
          class="portal-button portal-button--secondary self-start"
          :disabled="cancelForm.processing"
          @click="cancelBooking"
        >
          {{ props.booking.pendingReview ? 'Отозвать заявку' : 'Отменить запись' }}
        </button>
        <p
          v-if="cancelError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ cancelError }}
        </p>
      </section>

      <section
        class="portal-panel portal-stack portal-stack--tight"
        aria-labelledby="timezone-heading"
      >
        <h2
          id="timezone-heading"
          class="portal-heading portal-heading--section"
        >
          Часовой пояс
        </h2>
        <p class="portal-copy portal-copy--small">
          Время записи будет показано в выбранном часовом поясе.
        </p>
        <form
          class="portal-cluster"
          @submit.prevent="saveTimezone"
        >
          <select
            v-model="timezoneForm.timezone"
            required
            class="portal-input"
          >
            <option
              v-for="option in timezoneOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
          <button
            type="submit"
            class="portal-button portal-button--secondary"
            :disabled="timezoneForm.processing"
          >
            Сохранить
          </button>
        </form>
      </section>

      <section
        class="portal-stack"
        aria-labelledby="history-heading"
      >
        <h2
          id="history-heading"
          class="portal-heading portal-heading--section"
        >
          История записи
        </h2>
        <ol class="portal-stack portal-stack--tight">
          <li
            v-for="event in props.booking.history"
            :key="`${event.label}-${event.occurredAt}`"
            class="portal-copy portal-copy--small"
          >
            <span>{{ event.label }}</span>
            <span v-if="event.oldStartsAt && event.newStartsAt">
              — с
              <PortalDateTime
                :value="event.oldStartsAt"
                :time-zone="props.booking.timezone"
              />
              на
              <PortalDateTime
                :value="event.newStartsAt"
                :time-zone="props.booking.timezone"
              />
            </span>
            <span v-else>
              ·
              <PortalDateTime
                :value="event.occurredAt"
                :time-zone="props.booking.timezone"
              />
            </span>
          </li>
        </ol>
      </section>

      <div class="portal-cluster">
        <Link
          :href="props.urls.index"
          class="portal-button portal-button--secondary"
        >
          К моим записям
        </Link>
        <Link
          :href="props.urls.services"
          class="portal-link"
        >
          Услуги
        </Link>
      </div>
    </section>
  </main>
</template>
