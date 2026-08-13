<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PortalDateTime from '../../Components/PortalDateTime.vue';

type VisitFormat = 'office' | 'home' | 'online';

type ServiceOption = {
    id: number;
    name: string;
    summary: string;
    formats: VisitFormat[];
};

type SpecialistOption = {
    id: number;
    displayName: string;
    timezone: string | null;
};

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    blockingEndsAt: string;
    scheduleTimezone: string;
    displayTimezone: string;
    format: VisitFormat;
};

type Availability = {
    specialistId: number;
    serviceId: number;
    scheduleTimezone: string;
    displayTimezone: string;
    slots: AvailabilitySlot[];
};

type BookingQuery = {
    serviceId: number | null;
    specialistId: number | null;
    dateFrom: string;
    dateTo: string;
    format: VisitFormat;
    displayTimezone: string;
};

type BookingResult = {
    status: 'requested' | 'pending_review';
} | null;

type Props = {
    services: ServiceOption[];
    specialists: SpecialistOption[];
    availability: Availability | null;
    query: BookingQuery;
    client: { timezone: string };
    bookingResult: BookingResult;
    urls: { create: string; store: string; services: string };
};

const props = defineProps<Props>();
const selectedStart = ref<string | null>(null);
const bookingForm = useForm<{
    service_id: number | null;
    specialist_id: number | null;
    starts_at: string | null;
    format: VisitFormat;
    client_timezone: string;
}>({
    service_id: props.query.serviceId,
    specialist_id: props.query.specialistId,
    starts_at: null,
    format: props.query.format,
    client_timezone: props.query.displayTimezone,
});

const selectedService = computed(() =>
    props.services.find((service) => service.id === props.query.serviceId),
);
const formatOptions = computed<VisitFormat[]>(() =>
    selectedService.value?.formats ?? ['office', 'home', 'online'],
);
const bookingError = computed(() =>
    bookingForm.errors.starts_at
    ?? (bookingForm.errors as Record<string, string | undefined>).assignment,
);

const formatLabels: Record<VisitFormat, string> = {
    office: 'Office',
    home: 'Home visit',
    online: 'Online',
};

function selectSlot(slot: AvailabilitySlot): void {
    selectedStart.value = slot.startsAt;
    bookingForm.starts_at = slot.startsAt;
}

function submitBooking(): void {
    if (selectedStart.value === null) {
        return;
    }

    bookingForm.service_id = props.query.serviceId;
    bookingForm.specialist_id = props.query.specialistId;
    bookingForm.format = props.query.format;
    bookingForm.client_timezone = props.query.displayTimezone;
    bookingForm.starts_at = selectedStart.value;
    bookingForm.post(props.urls.store, { preserveScroll: true });
}
</script>

<template>
  <Head title="Book a visit" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Client booking
          </p>
          <h1 class="portal-heading portal-heading--page">
            Find a suitable time
          </h1>
          <p class="portal-lede">
            Choose an eligible service and specialist. Times are shown in your selected timezone.
          </p>
        </div>
      </header>

      <p
        v-if="props.bookingResult"
        class="portal-notice"
        role="status"
      >
        <span v-if="props.bookingResult.status === 'pending_review'">
          Your home-visit request was sent for CRM review. The time is not reserved until it is approved.
        </span>
        <span v-else>
          Your booking request was created. The selected time is protected while the booking is active.
        </span>
      </p>

      <form
        :action="props.urls.create"
        method="get"
        class="portal-panel portal-stack"
      >
        <div class="portal-field">
          <label
            for="booking-service"
            class="portal-label"
          >Service</label>
          <select
            id="booking-service"
            name="service_id"
            required
            class="portal-input"
            :value="props.query.serviceId ?? ''"
          >
            <option
              value=""
              disabled
            >
              Select a service
            </option>
            <option
              v-for="service in props.services"
              :key="service.id"
              :value="service.id"
            >
              {{ service.name }}
            </option>
          </select>
        </div>

        <div class="portal-field">
          <label
            for="booking-specialist"
            class="portal-label"
          >Specialist</label>
          <select
            id="booking-specialist"
            name="specialist_id"
            required
            class="portal-input"
            :value="props.query.specialistId ?? ''"
            :disabled="props.specialists.length === 0"
          >
            <option
              value=""
              disabled
            >
              Select a specialist
            </option>
            <option
              v-for="specialist in props.specialists"
              :key="specialist.id"
              :value="specialist.id"
            >
              {{ specialist.displayName }}
            </option>
          </select>
          <p
            v-if="props.query.serviceId && props.specialists.length === 0"
            class="portal-copy portal-copy--small"
          >
            No active specialist is currently assigned to this service.
          </p>
        </div>

        <div class="portal-grid portal-grid--two">
          <div class="portal-field">
            <label
              for="booking-date-from"
              class="portal-label"
            >From</label>
            <input
              id="booking-date-from"
              name="date_from"
              type="date"
              required
              class="portal-input"
              :value="props.query.dateFrom"
            >
          </div>
          <div class="portal-field">
            <label
              for="booking-date-to"
              class="portal-label"
            >To</label>
            <input
              id="booking-date-to"
              name="date_to"
              type="date"
              required
              class="portal-input"
              :value="props.query.dateTo"
            >
          </div>
        </div>

        <div class="portal-field">
          <label
            for="booking-format"
            class="portal-label"
          >Visit format</label>
          <select
            id="booking-format"
            name="format"
            required
            class="portal-input"
            :value="props.query.format"
          >
            <option
              v-for="format in formatOptions"
              :key="format"
              :value="format"
            >
              {{ formatLabels[format] }}
            </option>
          </select>
        </div>

        <div class="portal-field">
          <label
            for="booking-timezone"
            class="portal-label"
          >Display timezone</label>
          <input
            id="booking-timezone"
            name="display_timezone"
            type="text"
            required
            maxlength="64"
            class="portal-input"
            :value="props.query.displayTimezone"
            aria-describedby="booking-timezone-help"
          >
          <p
            id="booking-timezone-help"
            class="portal-copy portal-copy--small"
          >
            Your profile timezone is {{ props.client.timezone }}. You can override it with an IANA timezone such as Europe/Berlin.
          </p>
        </div>

        <button
          type="submit"
          class="portal-button portal-button--primary self-start"
        >
          Find available times
        </button>
      </form>

      <section
        v-if="props.availability"
        class="portal-stack"
        aria-labelledby="booking-times-heading"
      >
        <div class="portal-stack portal-stack--tight">
          <h2
            id="booking-times-heading"
            class="portal-heading portal-heading--section"
          >
            Available times
          </h2>
          <p class="portal-copy portal-copy--small">
            {{ props.availability.displayTimezone }} · specialist schedule {{ props.availability.scheduleTimezone }}
          </p>
        </div>

        <p
          v-if="props.availability.slots.length === 0"
          class="portal-notice"
          role="status"
        >
          No times are currently available for this selection.
        </p>

        <div
          v-else
          class="portal-grid portal-grid--cards"
          aria-label="Available appointment times"
        >
          <button
            v-for="slot in props.availability.slots"
            :key="slot.startsAt"
            type="button"
            class="portal-card portal-card--interactive"
            :aria-pressed="selectedStart === slot.startsAt"
            @click="selectSlot(slot)"
          >
            <span class="portal-heading portal-heading--card">
              <PortalDateTime
                :value="slot.startsAt"
                :time-zone="props.availability.displayTimezone"
              />
            </span>
            <span class="portal-card__summary">
              Ends
              <PortalDateTime
                :value="slot.endsAt"
                :time-zone="props.availability.displayTimezone"
              />
            </span>
          </button>
        </div>
      </section>

      <section
        v-if="selectedStart && props.availability"
        class="portal-panel portal-panel--accent portal-stack portal-stack--tight"
        aria-labelledby="booking-confirm-heading"
      >
        <h2
          id="booking-confirm-heading"
          class="portal-heading portal-heading--section"
        >
          Confirm your selection
        </h2>
        <p class="portal-copy">
          <PortalDateTime
            :value="selectedStart"
            :time-zone="props.availability.displayTimezone"
          />
          · {{ formatLabels[props.query.format] }}
        </p>
        <p
          v-if="props.query.format === 'home'"
          class="portal-copy portal-copy--small"
        >
          Home visits are requests for CRM review. This time will not be reserved until approval.
        </p>
        <p
          v-if="bookingError"
          class="portal-notice portal-notice--error"
          role="alert"
        >
          {{ bookingError }}
        </p>
        <button
          type="button"
          class="portal-button portal-button--primary self-start"
          :disabled="bookingForm.processing"
          @click="submitBooking"
        >
          {{ bookingForm.processing ? 'Submitting…' : props.query.format === 'home' ? 'Request home visit' : 'Create booking' }}
        </button>
      </section>

      <Link
        :href="props.urls.services"
        class="portal-button portal-button--secondary self-start"
      >
        Back to services
      </Link>
    </section>
  </main>
</template>
