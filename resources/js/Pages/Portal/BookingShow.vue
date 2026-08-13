<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    scheduleTimezone: string;
    formatLabel: string;
    format: 'office' | 'home' | 'online';
    status: string;
    statusLabel: string;
    paymentStatus: string;
    location: string | null;
    meetingUrl: string | null;
    partySize: number;
    canCancel: boolean;
    canReschedule: boolean;
    contactStaff: boolean;
    pendingReview: boolean;
    history: { eventType: string; status: string | null; startsAt: string | null; occurredAt: string }[];
};

const props = defineProps<{
    booking: Booking;
    availability: { displayTimezone: string; slots: Slot[] } | null;
    client: { timezone: string };
    urls: { index: string; cancel: string; reschedule: string; timezone: string; services: string };
}>();

const selectedSlot = ref<string | null>(null);
const cancelForm = useForm<{ reason: string | null }>({ reason: null });
const rescheduleForm = useForm<{ starts_at: string | null; client_timezone: string; reason: string | null }>({
    starts_at: null,
    client_timezone: props.booking.timezone,
    reason: null,
});
const timezoneForm = useForm<{ timezone: string }>({ timezone: props.booking.timezone });

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
  <Head title="Booking details" />
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
          Appointment
        </h2>
        <p class="portal-heading portal-heading--card">
          <PortalDateTime
            :value="props.booking.startsAt"
            :time-zone="props.booking.timezone"
          />
        </p>
        <p class="portal-copy portal-copy--small">
          Ends {{ props.booking.localEndsAt }} · {{ props.booking.timezone }}
        </p>
        <p class="portal-copy portal-copy--small">
          Schedule timezone: {{ props.booking.scheduleTimezone }}
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
          Open meeting link
        </a>
        <p
          v-if="props.booking.pendingReview"
          class="portal-notice"
        >
          This home-visit request is awaiting CRM review and does not reserve the specialist's time yet.
        </p>
        <p
          v-if="props.booking.contactStaff"
          class="portal-notice"
        >
          Self-service changes are closed inside the configured cutoff. Please contact staff.
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
          Choose another time
        </h2>
        <p class="portal-copy portal-copy--small">
          Available times are authoritative and shown in {{ props.availability.displayTimezone }}.
        </p>
        <p
          v-if="props.availability.slots.length === 0"
          class="portal-notice"
        >
          No alternative times are currently available.
        </p>
        <div
          v-else
          class="portal-grid portal-grid--cards"
          aria-label="Available reschedule times"
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
          {{ rescheduleForm.processing ? 'Saving…' : 'Reschedule booking' }}
        </button>
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
          Cancel
        </h2>
        <p class="portal-copy portal-copy--small">
          Payment status is separate from this booking change.
        </p>
        <button
          type="button"
          class="portal-button portal-button--secondary self-start"
          :disabled="cancelForm.processing"
          @click="cancelBooking"
        >
          {{ props.booking.pendingReview ? 'Withdraw request' : 'Cancel booking' }}
        </button>
      </section>

      <section
        class="portal-panel portal-stack portal-stack--tight"
        aria-labelledby="timezone-heading"
      >
        <h2
          id="timezone-heading"
          class="portal-heading portal-heading--section"
        >
          Display timezone
        </h2>
        <p class="portal-copy portal-copy--small">
          Your profile timezone is {{ props.client.timezone }}. Use an IANA timezone when travelling.
        </p>
        <form
          class="portal-cluster"
          @submit.prevent="saveTimezone"
        >
          <input
            v-model="timezoneForm.timezone"
            type="text"
            maxlength="64"
            required
            class="portal-input"
          >
          <button
            type="submit"
            class="portal-button portal-button--secondary"
            :disabled="timezoneForm.processing"
          >
            Save timezone
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
          Booking history
        </h2>
        <ol class="portal-stack portal-stack--tight">
          <li
            v-for="event in props.booking.history"
            :key="`${event.eventType}-${event.occurredAt}`"
            class="portal-copy portal-copy--small"
          >
            {{ event.eventType }} · {{ event.status ?? 'updated' }} · {{ event.occurredAt }}
          </li>
        </ol>
      </section>

      <div class="portal-cluster">
        <Link
          :href="props.urls.index"
          class="portal-button portal-button--secondary"
        >
          Back to my bookings
        </Link>
        <Link
          :href="props.urls.services"
          class="portal-link"
        >
          Services
        </Link>
      </div>
    </section>
  </main>
</template>
