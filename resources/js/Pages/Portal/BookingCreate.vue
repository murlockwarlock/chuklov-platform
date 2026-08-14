<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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

type Props = {
    services: ServiceOption[];
    specialists: SpecialistOption[];
    availability: Availability | null;
    query: BookingQuery;
    bookingResult: BookingResult;
    urls: { create: string; store: string; services: string; bookings: string };
};

const props = defineProps<Props>();
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
const bookingFormErrors = computed(() => bookingForm.errors as Record<string, string | undefined>);
const bookingError = computed(() =>
    bookingFormErrors.value.starts_at
    ?? bookingFormErrors.value.startsAt
    ?? bookingFormErrors.value.assignment
    ?? bookingFormErrors.value.service_id
    ?? bookingFormErrors.value.format
    ?? bookingFormErrors.value.party_size
    ?? bookingFormErrors.value.location,
);

const formatLabels: Record<VisitFormat, string> = {
    office: 'В клинике',
    home: 'Выезд на дом',
    online: 'Онлайн',
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
    bookingForm.starts_at = selectedStart.value;
    bookingForm.post(props.urls.store, { preserveScroll: true });
}
</script>

<template>
  <Head title="Запись" />
  <main class="portal-page">
    <section class="portal-container portal-container--narrow portal-stack portal-stack--loose">
      <header class="portal-masthead">
        <div class="portal-masthead__copy portal-stack portal-stack--tight">
          <p class="portal-eyebrow">
            Запись
          </p>
          <h1 class="portal-heading portal-heading--page">
            Выберите удобное время
          </h1>
          <p class="portal-lede">
            Выберите услугу, специалиста, формат и время.
          </p>
        </div>
      </header>

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
        <div class="portal-field">
          <label
            for="booking-service"
            class="portal-label"
          >Услуга</label>
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
              Выберите услугу
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
            >Количество человек</label>
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
            >Адрес</label>
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

        <div class="portal-field">
          <label
            for="booking-specialist"
            class="portal-label"
          >Специалист</label>
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
              Выберите специалиста
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
            Для этой услуги сейчас нет доступного специалиста.
          </p>
        </div>

        <div class="portal-grid portal-grid--two">
          <div class="portal-field">
            <label
              for="booking-date-from"
              class="portal-label"
            >С даты</label>
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
            >По дату</label>
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
          >Формат</label>
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

        <button
          type="submit"
          class="portal-button portal-button--primary self-start"
        >
          Показать свободное время
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
            Свободное время
          </h2>
        </div>

        <p
          v-if="props.availability.slots.length === 0"
          class="portal-notice"
          role="status"
        >
          Для выбранных параметров свободного времени пока нет.
        </p>

        <div
          v-else
          class="portal-grid portal-grid--cards"
          aria-label="Свободное время для записи"
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
              До
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
          Подтвердите запись
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
          Для выезда на дом мы отдельно подтвердим время.
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
          {{ bookingForm.processing ? 'Сохраняем…' : props.query.format === 'home' ? 'Отправить заявку' : 'Записаться' }}
        </button>
      </section>

      <Link
        :href="props.urls.services"
        class="portal-button portal-button--secondary self-start"
      >
        К услугам
      </Link>
      <Link
        :href="props.urls.bookings"
        class="portal-link self-start"
      >
        Мои записи
      </Link>
    </section>
  </main>
</template>
