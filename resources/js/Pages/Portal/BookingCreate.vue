<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import PortalDateTime from '../../Components/PortalDateTime.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

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
};

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayTimezone: string;
    format: VisitFormat;
};

type Availability = {
    specialistId: number;
    serviceId: number;
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
    message: string;
    bookingId: number;
} | null;

type SlotDay = {
    key: string;
    firstSlot: AvailabilitySlot;
    slots: AvailabilitySlot[];
};

type Props = {
    portal: PortalShell;
    services: ServiceOption[];
    specialists: SpecialistOption[];
    availability: Availability | null;
    query: BookingQuery;
    bookingResult: BookingResult;
    urls: { create: string; store: string; services: string; bookings: string };
};

const props = defineProps<Props>();
const { locale, t } = usePortalLocale();
const selectedStart = ref<string | null>(null);
const bookingForm = useForm<{
    service_id: number | null;
    specialist_id: number | null;
    starts_at: string | null;
    format: VisitFormat;
    party_size: number;
    location: string | null;
}>({
    service_id: props.query.serviceId,
    specialist_id: props.query.specialistId,
    starts_at: null,
    format: props.query.format,
    party_size: 1,
    location: null,
});

const acknowledgedBookingId = ref<number | null>(null);

watch(
    () => props.bookingResult?.bookingId ?? null,
    (bookingId) => {
        if (bookingId === null || bookingId === acknowledgedBookingId.value) {
            return;
        }

        acknowledgedBookingId.value = bookingId;
        selectedStart.value = null;
        bookingForm.starts_at = null;
    },
    { immediate: true },
);

const selectedService = computed(() =>
    props.services.find((service) => service.id === props.query.serviceId),
);
const formatOptions = computed<VisitFormat[]>(() =>
    selectedService.value?.formats ?? ['office', 'home', 'online'],
);
const singleSpecialist = computed(() =>
    props.specialists.length === 1 ? props.specialists[0] : null,
);
const slotDays = computed<SlotDay[]>(() => {
    if (!props.availability) {
        return [];
    }

    const groups = new Map<string, SlotDay>();

    for (const slot of props.availability.slots) {
        const parts = new Intl.DateTimeFormat('en-CA', {
            day: '2-digit',
            month: '2-digit',
            timeZone: props.availability.displayTimezone,
            year: 'numeric',
        }).formatToParts(new Date(slot.startsAt));
        const values = Object.fromEntries(
            parts
                .filter((part) => ['day', 'month', 'year'].includes(part.type))
                .map((part) => [part.type, part.value]),
        ) as Record<'day' | 'month' | 'year', string>;
        const key = `${values.year}-${values.month}-${values.day}`;
        const existing = groups.get(key);

        if (existing) {
            existing.slots.push(slot);
        } else {
            groups.set(key, { key, firstSlot: slot, slots: [slot] });
        }
    }

    return Array.from(groups.values());
});
const bookingFormErrors = computed(() => bookingForm.errors as Record<string, string | undefined>);
const bookingError = computed(() =>
    bookingFormErrors.value.starts_at
    ?? bookingFormErrors.value.startsAt
    ?? bookingFormErrors.value.assignment
    ?? bookingFormErrors.value.service_id
    ?? bookingFormErrors.value.specialist_id
    ?? bookingFormErrors.value.format
    ?? bookingFormErrors.value.party_size
    ?? bookingFormErrors.value.location,
);

function formatLabel(format: VisitFormat): string {
    return t('booking.' + format);
}

function selectSlot(slot: AvailabilitySlot): void {
    selectedStart.value = slot.startsAt;
    bookingForm.starts_at = slot.startsAt;
}

function submitBooking(): void {
    if (selectedStart.value === null) {
        return;
    }

    bookingForm.service_id = props.query.serviceId;
    bookingForm.specialist_id = props.query.specialistId ?? singleSpecialist.value?.id ?? null;
    bookingForm.format = props.query.format;
    bookingForm.starts_at = selectedStart.value;
    bookingForm.post(props.urls.store, { preserveScroll: true });
}
</script>

<template>
  <AppShell
    :title="t('booking.title')"
    :portal="props.portal"
    active="services"
  >
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            {{ t('booking.title') }}
          </p>
          <h1 class="portal-heading portal-heading--page">
            {{ t('booking.dateTime') }}
          </h1>
          <p class="portal-lede">
            {{ t('booking.description') }}
          </p>
        </div>
      </header>

      <nav
        class="portal-booking-progress"
        :aria-label="t('booking.title')"
      >
        <span class="portal-booking-progress__step portal-booking-progress__step--active">
          <span class="portal-booking-progress__number">1</span>
          {{ t('booking.stepService') }}
        </span>
        <span
          class="portal-booking-progress__line"
          aria-hidden="true"
        />
        <span class="portal-booking-progress__step">
          <span class="portal-booking-progress__number">2</span>
          {{ t('booking.stepFormat') }}
        </span>
        <span
          class="portal-booking-progress__line"
          aria-hidden="true"
        />
        <span class="portal-booking-progress__step">
          <span class="portal-booking-progress__number">3</span>
          {{ t('booking.stepTime') }}
        </span>
        <span
          class="portal-booking-progress__line"
          aria-hidden="true"
        />
        <span class="portal-booking-progress__step">
          <span class="portal-booking-progress__number">4</span>
          {{ t('booking.stepConfirm') }}
        </span>
      </nav>

      <p
        v-if="props.bookingResult"
        class="portal-notice"
        role="status"
      >
        {{ props.bookingResult.message }}
      </p>

      <form
        :action="props.urls.create"
        method="get"
        class="portal-panel portal-stack"
      >
        <div class="portal-field portal-field--wide">
          <label
            for="booking-service"
            class="portal-label"
          >{{ t('booking.service') }}</label>
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
              {{ t('booking.chooseService') }}
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

        <div
          v-if="props.query.format === 'home'"
          class="portal-grid portal-grid--two"
        >
          <div class="portal-field">
            <label
              for="booking-party-size"
              class="portal-label"
            >{{ t('booking.partySize') }}</label>
            <input
              id="booking-party-size"
              v-model.number="bookingForm.party_size"
              name="party_size"
              type="number"
              min="1"
              max="20"
              required
              class="portal-input"
            >
          </div>
          <div class="portal-field">
            <label
              for="booking-location"
              class="portal-label"
            >{{ t('booking.address') }}</label>
            <input
              id="booking-location"
              v-model="bookingForm.location"
              name="location"
              type="text"
              maxlength="500"
              required
              class="portal-input"
            >
          </div>
        </div>

        <div
          v-if="props.query.serviceId && singleSpecialist"
          class="portal-booking-specialist"
        >
          <span class="portal-label">{{ t('booking.specialist') }}</span>
          <strong>{{ singleSpecialist.displayName }}</strong>
          <input
            type="hidden"
            name="specialist_id"
            :value="props.query.specialistId ?? singleSpecialist.id"
          >
        </div>

        <div
          v-else-if="props.query.serviceId && props.specialists.length > 1"
          class="portal-field"
        >
          <label
            for="booking-specialist"
            class="portal-label"
          >{{ t('booking.specialist') }}</label>
          <select
            id="booking-specialist"
            name="specialist_id"
            required
            class="portal-input"
            :value="props.query.specialistId ?? ''"
          >
            <option
              value=""
              disabled
            >
              {{ t('booking.chooseSpecialist') }}
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
          v-if="props.query.serviceId && props.specialists.length === 0"
          class="portal-notice portal-notice--warning"
          role="status"
        >
          {{ t('booking.noSpecialists') }}
        </p>

        <div class="portal-grid portal-grid--two portal-booking-date-window">
          <div class="portal-field">
            <label
              for="booking-date-from"
              class="portal-label"
            >{{ t('booking.from') }}</label>
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
            >{{ t('booking.to') }}</label>
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
          >{{ t('booking.format') }}</label>
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
              {{ formatLabel(format) }}
            </option>
          </select>
        </div>

        <button
          type="submit"
          class="portal-button portal-button--primary self-start"
        >
          {{ t('booking.chooseTime') }}
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
            {{ t('booking.chooseTime') }}
          </h2>
        </div>

        <p
          v-if="props.availability.slots.length === 0"
          class="portal-notice"
          role="status"
        >
          {{ t('booking.noSlots') }}
        </p>

        <div
          v-else
          class="portal-slot-days"
          :aria-label="t('booking.chooseTime')"
        >
          <section
            v-for="day in slotDays"
            :key="day.key"
            class="portal-slot-day"
          >
            <header class="portal-slot-day__header">
              <p class="portal-kicker">
                <PortalDateTime
                  :value="day.firstSlot.startsAt"
                  :time-zone="props.availability.displayTimezone"
                  :locale="locale"
                  mode="date"
                />
              </p>
            </header>
            <div class="portal-slot-grid">
              <button
                v-for="slot in day.slots"
                :key="slot.startsAt"
                type="button"
                class="portal-slot"
                data-testid="availability-slot"
                :aria-pressed="selectedStart === slot.startsAt"
                @click="selectSlot(slot)"
              >
                <span class="portal-slot__time">
                  <PortalDateTime
                    :value="slot.startsAt"
                    :time-zone="props.availability.displayTimezone"
                    :locale="locale"
                    mode="time"
                  />
                </span>
                <span class="portal-slot__until">
                  {{ t('booking.until') }}
                  <PortalDateTime
                    :value="slot.endsAt"
                    :time-zone="props.availability.displayTimezone"
                    :locale="locale"
                    mode="time"
                  />
                </span>
              </button>
            </div>
          </section>
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
          {{ t('booking.create') }}
        </h2>
        <p class="portal-copy">
          <PortalDateTime
            :value="selectedStart"
            :time-zone="props.availability.displayTimezone"
            :locale="locale"
          />
          · {{ formatLabel(props.query.format) }}
        </p>
        <p
          v-if="props.query.format === 'home'"
          class="portal-copy portal-copy--small"
        >
          {{ t('booking.requestSent') }}
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
          {{ bookingForm.processing ? t('booking.creating') : t('booking.create') }}
        </button>
      </section>

      <Link
        :href="props.urls.services"
        class="portal-button portal-button--secondary self-start"
      >
        {{ t('services.title') }}
      </Link>
      <Link
        :href="props.urls.bookings"
        class="portal-link self-start"
      >
        {{ t('bookings.title') }}
      </Link>
    </section>
  </AppShell>
</template>
