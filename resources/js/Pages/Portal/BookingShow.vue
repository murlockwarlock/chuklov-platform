<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCalendar from '../../Components/Portal/BookingCalendar.vue';
import PortalDateTime from '../../Components/PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type Slot = {
    startsAt: string;
    endsAt: string;
    displayUtcOffset: string;
    displayTimezone: string;
    format: 'office' | 'home' | 'online';
};

type WorkingLocation = {
    id: number;
    name: string;
    address: string;
    timezone: string;
    isDefault: boolean;
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
    displayUtcOffset: string;
    timezone: string;
    formatLabel: string;
    format: 'office' | 'home' | 'online';
    statusLabel: string;
    paymentStatusLabel: string;
    location: string | null;
    meetingUrl: string | null;
    meetingPending: boolean;
    partySize: number;
    eventVersion: number;
    canCancel: boolean;
    canReschedule: boolean;
    pendingReview: boolean;
    workingLocationId: number | null;
    locationArea: string | null;
    locationSnapshot: {
        name?: string;
        address?: string;
        timezone?: string;
        area_name?: string;
        [key: string]: unknown;
    };
    history: { label: string; oldStartsAt: string | null; newStartsAt: string | null; occurredAt: string }[];
};

type AvailabilityRange = {
    dateFrom: string;
    dateTo: string;
};

const props = defineProps<{
    portal: PortalShell;
    booking: Booking;
    availability: { displayTimezone: string; slots: Slot[] } | null;
    availabilityRange: AvailabilityRange | null;
    client: { timezone: string };
    workingLocations: WorkingLocation[];
    locationDays: Array<{ areaName: string; timezone: string }>;
    urls: { index: string; show: string; cancel: string; reschedule: string; timezone: string; services: string };
}>();

const { locale, t } = usePortalLocale();
const selectedSlot = ref<string | null>(null);
const selectedDate = ref<string | null>(props.booking.localDate);
const rescheduleOpen = ref(props.availability !== null);
const rescheduleLoading = ref(false);
const meetingReloading = ref(false);
const savedWorkingLocation = props.workingLocations.find((location) => location.id === props.booking.workingLocationId);
const defaultWorkingLocation = props.workingLocations.find((location) => location.isDefault) ?? props.workingLocations[0] ?? null;
const initialWorkingLocationId = savedWorkingLocation?.id ?? defaultWorkingLocation?.id ?? null;
const locationDayAreas = Array.from(new Set(props.locationDays.map((locationDay) => locationDay.areaName)));
const initialLocationArea = props.booking.locationArea !== null && locationDayAreas.includes(props.booking.locationArea)
    ? props.booking.locationArea
    : locationDayAreas[0] ?? props.booking.locationArea;
const cancelForm = useForm<{ reason: string | null }>({ reason: null });
const rescheduleForm = useForm<{
    starts_at: string | null;
    client_timezone: string;
    working_location_id: number | null;
    location_area: string | null;
    reason: string | null;
    expected_event_version: number;
}>({
    starts_at: null,
    client_timezone: props.client.timezone,
    working_location_id: initialWorkingLocationId,
    location_area: initialLocationArea,
    reason: null,
    expected_event_version: props.booking.eventVersion,
});
const rescheduleError = computed(() => {
    const errors = rescheduleForm.errors as Record<string, string | undefined>;

    return errors.starts_at
        ?? errors.startsAt
        ?? errors.booking
        ?? errors.expected_event_version;
});
const cancelError = computed(() => (cancelForm.errors as Record<string, string | undefined>).booking);
let meetingPollTimer: ReturnType<typeof setInterval> | null = null;
let meetingPollStartedAt: number | null = null;
const meetingPollTimeoutMs = 120000;

function stopMeetingPolling(): void {
    if (meetingPollTimer === null) {
        return;
    }

    window.clearInterval(meetingPollTimer);
    meetingPollTimer = null;
    meetingPollStartedAt = null;
}

function refreshPendingMeeting(): void {
    if (!props.booking.meetingPending || props.booking.meetingUrl !== null) {
        stopMeetingPolling();

        return;
    }

    if (meetingReloading.value) {
        return;
    }

    meetingPollStartedAt ??= Date.now();
    if (Date.now() - meetingPollStartedAt >= meetingPollTimeoutMs) {
        stopMeetingPolling();

        return;
    }

    meetingReloading.value = true;
    router.reload({
        only: ['booking'],
        onFinish: () => {
            meetingReloading.value = false;
        },
    });
}

function syncMeetingPolling(): void {
    if (!props.booking.meetingPending || props.booking.meetingUrl !== null) {
        stopMeetingPolling();

        return;
    }

    if (meetingPollTimer === null) {
        meetingPollStartedAt ??= Date.now();
        meetingPollTimer = window.setInterval(refreshPendingMeeting, 5000);
    }
}

onMounted(syncMeetingPolling);
onBeforeUnmount(stopMeetingPolling);

watch(
    () => [props.booking.meetingPending, props.booking.meetingUrl] as const,
    syncMeetingPolling,
);

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
        rescheduleForm.client_timezone = timezone;
    },
    { immediate: true },
);

function selectSlot(slot: Slot): void {
    selectedSlot.value = slot.startsAt;
    rescheduleForm.starts_at = slot.startsAt;
}

function selectDate(date: string): void {
    selectedDate.value = date;
    selectedSlot.value = null;
    rescheduleForm.starts_at = null;
}

function dateKey(date: Date): string {
    return [date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate()]
        .map((part, index) => index === 0 ? String(part) : String(part).padStart(2, '0'))
        .join('-');
}

function monthRange(dateValue: string): AvailabilityRange {
    const [year, month] = dateValue.split('-').map(Number);
    const fallback = new Date();
    const resolvedYear = Number.isInteger(year) ? year : fallback.getUTCFullYear();
    const resolvedMonth = Number.isInteger(month) ? month - 1 : fallback.getUTCMonth();
    const first = new Date(Date.UTC(resolvedYear, resolvedMonth, 1));
    const last = new Date(Date.UTC(resolvedYear, resolvedMonth + 1, 0));

    return {
        dateFrom: dateKey(first),
        dateTo: dateKey(last),
    };
}

function loadAvailability(range: AvailabilityRange): void {
    rescheduleOpen.value = true;
    rescheduleLoading.value = true;
    selectedDate.value = range.dateFrom;
    selectedSlot.value = null;
    rescheduleForm.starts_at = null;

    router.get(props.urls.show, {
        reschedule: 1,
        date_from: range.dateFrom,
        date_to: range.dateTo,
        display_timezone: props.client.timezone,
        working_location_id: rescheduleForm.working_location_id ?? undefined,
        location_area: rescheduleForm.location_area ?? undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            rescheduleLoading.value = false;
        },
    });
}

function changeWorkingLocation(value: string): void {
    rescheduleForm.working_location_id = value === '' ? null : Number(value);
    loadAvailability(props.availabilityRange ?? monthRange(props.booking.localDate));
}

function changeLocationArea(value: string): void {
    rescheduleForm.location_area = value || null;
    loadAvailability(props.availabilityRange ?? monthRange(props.booking.localDate));
}

function openReschedule(): void {
    if (props.availability !== null && props.availabilityRange !== null) {
        rescheduleOpen.value = true;

        return;
    }

    loadAvailability(monthRange(props.booking.localDate));
}

function closeReschedule(): void {
    rescheduleOpen.value = false;
    selectedSlot.value = null;
    rescheduleForm.starts_at = null;
}

function changeMonth(dateFrom: string, dateTo: string): void {
    loadAvailability({ dateFrom, dateTo });
}

function cancelBooking(): void {
    cancelForm.post(props.urls.cancel, { preserveScroll: true });
}

function rescheduleBooking(): void {
    if (selectedSlot.value === null) {
        return;
    }

    rescheduleForm.post(props.urls.reschedule, {
        preserveScroll: true,
        onSuccess: () => {
            rescheduleOpen.value = false;
            selectedSlot.value = null;
            rescheduleForm.starts_at = null;
        },
    });
}

</script>

<template>
  <AppShell
    :title="props.booking.service.name"
    :portal="props.portal"
    active="bookings"
  >
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
          {{ t('booking.dateTime') }}
        </h2>
        <p class="portal-heading portal-heading--card">
          <PortalDateTime
            :value="props.booking.startsAt"
            :time-zone="props.booking.timezone"
            :locale="locale"
            mode="date"
          />
          <span aria-hidden="true"> · </span>
          <span>{{ props.booking.localTime }}–{{ props.booking.localEndsAt }} · UTC{{ props.booking.displayUtcOffset }}</span>
        </p>
        <section
          v-if="props.booking.locationSnapshot.address || props.booking.locationSnapshot.name || props.booking.locationArea"
          class="portal-booking-location-panel portal-stack portal-stack--tight"
          aria-labelledby="booking-location-heading"
        >
          <h3
            id="booking-location-heading"
            class="portal-heading portal-heading--card"
          >
            {{ props.booking.locationSnapshot.name ?? (props.booking.format === 'home' ? t('booking.home') : t('booking.location')) }}
          </h3>
          <span
            v-if="props.booking.locationArea"
            class="portal-copy"
          >{{ t('booking.area') }}: {{ props.booking.locationArea }}</span>
          <span
            v-if="props.booking.locationSnapshot.address || props.booking.location"
            class="portal-copy"
          >{{ props.booking.locationSnapshot.address ?? props.booking.location }}</span>
          <span
            v-if="props.booking.locationSnapshot.timezone"
            class="portal-copy portal-copy--small"
          >{{ t('booking.byLocationTime') }}: {{ props.booking.locationSnapshot.timezone }}</span>
        </section>
        <a
          v-if="props.booking.meetingUrl"
          :href="props.booking.meetingUrl"
          rel="noopener noreferrer"
          target="_blank"
          class="portal-button portal-button--primary self-start"
        >
          {{ t('booking.meeting') }}
        </a>
        <p
          v-if="props.booking.meetingPending"
          class="portal-notice"
          role="status"
        >
          {{ t('booking.meetingPending') }}
        </p>
        <p
          v-if="props.booking.pendingReview"
          class="portal-notice"
        >
          {{ t('booking.requestSent') }}
        </p>
        <div
          v-if="props.booking.canReschedule || props.booking.canCancel"
          class="portal-form-actions portal-cluster"
        >
          <button
            v-if="props.booking.canReschedule"
            type="button"
            class="portal-button portal-button--primary"
            :disabled="rescheduleLoading"
            @click="openReschedule"
          >
            {{ rescheduleLoading ? t('common.loading') : t('booking.reschedule') }}
          </button>
          <button
            v-if="props.booking.canCancel"
            type="button"
            class="portal-button portal-button--secondary"
            :disabled="cancelForm.processing"
            @click="cancelBooking"
          >
            {{ cancelForm.processing ? t('common.loading') : t('booking.cancel') }}
          </button>
        </div>
        <p
          v-if="cancelError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ cancelError }}
        </p>
      </section>

      <section
        v-if="rescheduleOpen"
        class="portal-panel portal-stack"
        :aria-label="t('booking.reschedule')"
      >
        <header class="portal-section-heading">
          <div class="portal-stack portal-stack--tight">
            <p class="portal-eyebrow">
              {{ t('booking.reschedule') }}
            </p>
            <h2 class="portal-heading portal-heading--section">
              {{ t('booking.chooseNewDateTime') }}
            </h2>
          </div>
          <button
            type="button"
            class="portal-link portal-link--button"
            @click="closeReschedule"
          >
            {{ t('booking.backToDetails') }}
          </button>
        </header>
        <p
          v-if="rescheduleLoading"
          class="portal-notice"
        >
          {{ t('booking.loadingAvailability') }}
        </p>
        <section
          v-if="props.booking.format === 'office' && props.workingLocations.length > 0"
          class="portal-stack portal-stack--tight"
        >
          <label class="portal-field">
            <span class="portal-label">{{ t('booking.whereMeeting') }}</span>
            <select
              class="portal-input portal-select"
              :value="rescheduleForm.working_location_id ?? ''"
              data-testid="booking-reschedule-location-select"
              @change="changeWorkingLocation(($event.target as HTMLSelectElement).value)"
            >
              <option
                v-for="location in props.workingLocations"
                :key="location.id"
                :value="location.id"
              >{{ location.name }} — {{ location.address }}</option>
            </select>
          </label>
        </section>
        <section
          v-if="props.booking.format === 'home' && props.locationDays.length > 0"
          class="portal-stack portal-stack--tight"
        >
          <label class="portal-field">
            <span class="portal-label">{{ t('booking.area') }}</span>
            <select
              class="portal-input portal-select"
              :value="rescheduleForm.location_area ?? ''"
              data-testid="booking-reschedule-area-select"
              @change="changeLocationArea(($event.target as HTMLSelectElement).value)"
            >
              <option
                v-for="area in Array.from(new Set(props.locationDays.map((day) => day.areaName)))"
                :key="area"
                :value="area"
              >{{ area }}</option>
            </select>
          </label>
        </section>
        <BookingCalendar
          v-if="props.availability && props.availabilityRange"
          :availability="props.availability"
          :date-from="props.availabilityRange.dateFrom"
          :date-to="props.availabilityRange.dateTo"
          :locale="locale"
          :selected-date="selectedDate"
          :selected-start="selectedSlot"
          :show-heading="false"
          @select-date="selectDate"
          @select-slot="selectSlot"
          @change-month="changeMonth"
        />
        <button
          v-if="props.availability && props.availabilityRange"
          type="button"
          class="portal-button portal-button--primary self-start"
          :disabled="rescheduleForm.processing || selectedSlot === null"
          @click="rescheduleBooking"
        >
          {{ rescheduleForm.processing ? t('profile.saving') : t('booking.confirmReschedule') }}
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
        class="portal-stack"
        aria-labelledby="history-heading"
      >
        <h2
          id="history-heading"
          class="portal-heading portal-heading--section"
        >
          {{ t('bookings.history') }}
        </h2>
        <ol class="portal-stack portal-stack--tight">
          <li
            v-for="event in props.booking.history"
            :key="`${event.label}-${event.occurredAt}`"
            class="portal-copy portal-copy--small"
          >
            <span>{{ event.label }}</span>
            <span v-if="event.oldStartsAt && event.newStartsAt">
              —
              <PortalDateTime
                :value="event.oldStartsAt"
                :time-zone="props.booking.timezone"
                :locale="locale"
              />
              →
              <PortalDateTime
                :value="event.newStartsAt"
                :time-zone="props.booking.timezone"
                :locale="locale"
              />
            </span>
            <span v-else>
              ·
              <PortalDateTime
                :value="event.occurredAt"
                :time-zone="props.booking.timezone"
                :locale="locale"
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
          {{ t('bookings.title') }}
        </Link>
        <Link
          :href="props.urls.services"
          class="portal-link"
        >
          {{ t('services.title') }}
        </Link>
      </div>
    </section>
  </AppShell>
</template>
