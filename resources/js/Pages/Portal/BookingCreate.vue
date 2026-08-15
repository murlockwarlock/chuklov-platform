<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppShell from '../../Components/Portal/AppShell.vue';
import BookingCalendar from '../../Components/Portal/BookingCalendar.vue';
import BookingChoiceList from '../../Components/Portal/BookingChoiceList.vue';
import BookingConfirmation from '../../Components/Portal/BookingConfirmation.vue';
import BookingSuccess from '../../Components/Portal/BookingSuccess.vue';
import { usePortalLocale } from '../../composables/usePortalLocale';
import type { PortalShell } from '../../types/portal';

type VisitFormat = 'office' | 'home' | 'online';
type BookingStep = 'time' | 'confirmation' | 'success';

type ServiceOption = {
    id: number;
    name: string;
    summary: string;
    formats: VisitFormat[];
    durationMinutes: number | null;
    priceMinor: number | null;
    priceCurrency: string | null;
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
    formatSelected: boolean;
    displayTimezone: string;
};

type BookingResult = {
    message: string;
    bookingId: number;
} | null;

type Props = {
    portal: PortalShell;
    services: ServiceOption[];
    specialists: SpecialistOption[];
    availability: Availability | null;
    query: BookingQuery;
    bookingResult: BookingResult;
    urls: { create: string; store: string; services: string; bookings: string };
};

type ProgressStep = {
    key: string;
    label: string;
};

const props = defineProps<Props>();
const { locale, t } = usePortalLocale();
const selectedServiceId = ref<number | null>(props.query.serviceId);
const selectedSpecialistId = ref<number | null>(props.query.specialistId);
const selectedFormat = ref<VisitFormat | null>(props.query.formatSelected ? props.query.format : null);
const selectedDate = ref<string | null>(props.query.dateFrom);
const selectedStart = ref<string | null>(null);
const bookingStep = ref<BookingStep>('time');
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
    () => [props.query.serviceId, props.query.specialistId, props.query.format, props.query.formatSelected, props.query.dateFrom] as const,
    ([serviceId, specialistId, format, formatSelected, dateFrom]) => {
        selectedServiceId.value = serviceId;
        selectedSpecialistId.value = specialistId;
        selectedFormat.value = formatSelected ? format : null;
        selectedDate.value = dateFrom;
        bookingForm.service_id = serviceId;
        bookingForm.specialist_id = specialistId;
        bookingForm.format = format;

        if (props.bookingResult === null) {
            selectedStart.value = null;
            bookingStep.value = 'time';
            bookingForm.starts_at = null;
        } else {
            bookingStep.value = 'success';
        }
    },
    { immediate: true },
);

watch(
    () => props.bookingResult?.bookingId ?? null,
    (bookingId) => {
        if (bookingId === null || bookingId === acknowledgedBookingId.value) {
            return;
        }

        acknowledgedBookingId.value = bookingId;
        bookingStep.value = 'success';
    },
    { immediate: true },
);

const selectedService = computed(() =>
    props.services.find((service) => service.id === props.query.serviceId) ?? null,
);
const selectedSpecialist = computed(() =>
    props.specialists.find((specialist) => specialist.id === props.query.specialistId) ?? null,
);
const formatOptions = computed<VisitFormat[]>(() => selectedService.value?.formats ?? []);
const needsSpecialistChoice = computed(() => props.query.serviceId !== null && props.specialists.length > 1 && props.query.specialistId === null);
const needsFormatChoice = computed(() => props.query.serviceId !== null && !needsSpecialistChoice.value && formatOptions.value.length > 1 && !props.query.formatSelected);
const hasSpecialistChoice = computed(() => props.specialists.length > 1);
const bookingCompleted = computed(() => props.bookingResult !== null || bookingStep.value === 'success');
const currentStepKey = computed(() => {
    if (bookingCompleted.value) {
        return 'confirmation';
    }

    if (props.query.serviceId === null) {
        return 'service';
    }

    if (needsSpecialistChoice.value) {
        return 'specialist';
    }

    if (needsFormatChoice.value) {
        return 'format';
    }

    return bookingStep.value === 'confirmation' ? 'confirmation' : 'time';
});
const progressSteps = computed<ProgressStep[]>(() => [
    { key: 'service', label: t('booking.stepService') },
    ...(hasSpecialistChoice.value
        ? [{ key: 'specialist', label: t('booking.stepSpecialist') }]
        : []),
    ...(formatOptions.value.length > 1
        ? [{ key: 'format', label: t('booking.stepFormat') }]
        : []),
    { key: 'time', label: t('booking.stepTime') },
    { key: 'confirmation', label: t('booking.stepConfirm') },
]);
const bookingError = computed(() => {
    const errors = bookingForm.errors as Record<string, string | undefined>;

    return errors.starts_at
        ?? errors.startsAt
        ?? errors.booking
        ?? errors.assignment
        ?? errors.service_id
        ?? errors.specialist_id
        ?? errors.format
        ?? errors.party_size
        ?? errors.location;
});

function durationLabel(service: ServiceOption): string | null {
    return service.durationMinutes === null
        ? null
        : t('service.durationMinutes', { value: service.durationMinutes });
}

function priceLabel(service: ServiceOption): string | null {
    if (service.priceMinor === null || service.priceCurrency === null) {
        return null;
    }

    return new Intl.NumberFormat(locale.value === 'ru' ? 'ru-RU' : 'en-GB', {
        style: 'currency',
        currency: service.priceCurrency,
        maximumFractionDigits: 0,
    }).format(service.priceMinor / 100);
}

const serviceChoices = computed(() => props.services.map((service) => ({
    id: service.id,
    title: service.name,
    description: service.summary,
    meta: durationLabel(service),
    trailing: priceLabel(service),
})));
const specialistChoices = computed(() => props.specialists.map((specialist) => ({
    id: specialist.id,
    title: specialist.displayName,
})));

function formatLabel(format: VisitFormat): string {
    return t('booking.' + format);
}

function bookingQuery(
    dateFrom = props.query.dateFrom,
    dateTo = props.query.dateTo,
    includeFormat = props.query.formatSelected,
): Record<string, string | number> {
    const query: Record<string, string | number> = {
        date_from: dateFrom,
        date_to: dateTo,
    };

    if (props.query.serviceId !== null) {
        query.service_id = props.query.serviceId;
    }

    if (props.query.specialistId !== null) {
        query.specialist_id = props.query.specialistId;
    }

    if (includeFormat) {
        query.format = props.query.format;
    }

    return query;
}

function visitBooking(query: Record<string, string | number>): void {
    router.get(props.urls.create, query, {
        preserveState: false,
        preserveScroll: false,
    });
}

function continueService(): void {
    if (selectedServiceId.value === null) {
        return;
    }

    const service = props.services.find((item) => item.id === selectedServiceId.value);
    const query: Record<string, string | number> = {
        date_from: props.query.dateFrom,
        date_to: props.query.dateTo,
        service_id: selectedServiceId.value,
    };

    if (service?.formats.length === 1) {
        query.format = service.formats[0];
    }

    visitBooking(query);
}

function continueSpecialist(): void {
    if (selectedSpecialistId.value === null || props.query.serviceId === null) {
        return;
    }

    const query: Record<string, string | number> = {
        ...bookingQuery(),
        service_id: props.query.serviceId,
        specialist_id: selectedSpecialistId.value,
    };

    if (formatOptions.value.length === 1) {
        query.format = formatOptions.value[0];
    }

    visitBooking(query);
}

function continueFormat(): void {
    if (selectedFormat.value === null || props.query.serviceId === null) {
        return;
    }

    visitBooking({
        ...bookingQuery(),
        format: selectedFormat.value,
    });
}

function changeService(): void {
    visitBooking({ date_from: props.query.dateFrom, date_to: props.query.dateTo });
}

function changeFormat(): void {
    if (props.query.serviceId === null) {
        return;
    }

    const query: Record<string, string | number> = {
        date_from: props.query.dateFrom,
        date_to: props.query.dateTo,
        service_id: props.query.serviceId,
    };

    if (props.query.specialistId !== null) {
        query.specialist_id = props.query.specialistId;
    }

    visitBooking(query);
}

function changeSpecialist(): void {
    if (props.query.serviceId === null) {
        return;
    }

    visitBooking({
        date_from: props.query.dateFrom,
        date_to: props.query.dateTo,
        service_id: props.query.serviceId,
    });
}

function changeMonth(dateFrom: string, dateTo: string): void {
    visitBooking(bookingQuery(dateFrom, dateTo));
}

function selectDate(date: string): void {
    selectedDate.value = date;
    selectedStart.value = null;
    bookingForm.starts_at = null;
}

function selectSlot(slot: AvailabilitySlot): void {
    selectedStart.value = slot.startsAt;
    bookingForm.starts_at = slot.startsAt;
}

function continueToConfirmation(): void {
    if (selectedStart.value !== null) {
        bookingStep.value = 'confirmation';
    }
}

function returnToTime(): void {
    bookingStep.value = 'time';
}

function submitBooking(): void {
    if (selectedStart.value === null || props.query.serviceId === null || props.query.specialistId === null) {
        return;
    }

    bookingForm.service_id = props.query.serviceId;
    bookingForm.specialist_id = props.query.specialistId;
    bookingForm.format = props.query.format;
    bookingForm.starts_at = selectedStart.value;
    bookingForm.post(props.urls.store, { preserveScroll: false });
}
</script>

<template>
  <AppShell
    :title="t('booking.title')"
    :portal="props.portal"
    active="services"
    :bottom-navigation="props.bookingResult !== null"
  >
    <section
      class="portal-container portal-container--booking portal-stack portal-stack--loose"
      :style="{ '--portal-step-count': progressSteps.length }"
    >
      <Link
        :href="props.urls.services"
        class="portal-booking-back"
      >
        <span aria-hidden="true">←</span>
        <span>{{ t('services.title') }}</span>
      </Link>

      <section class="portal-booking-flow portal-panel">
        <header
          v-if="!bookingCompleted"
          class="portal-booking-flow__header"
        >
          <div class="portal-booking-flow__title-wrap">
            <p class="portal-eyebrow">
              CHUKLOV
            </p>
            <h1 class="portal-heading portal-booking-flow__title">
              {{ t('booking.title') }}
            </h1>
          </div>
        </header>

        <nav
          v-if="!bookingCompleted"
          class="portal-booking-progress"
          :aria-label="t('booking.title')"
        >
          <template
            v-for="(step, index) in progressSteps"
            :key="step.key"
          >
            <span
              class="portal-booking-progress__step"
              :class="{
                'portal-booking-progress__step--active': currentStepKey === step.key,
                'portal-booking-progress__step--complete': progressSteps.findIndex((item) => item.key === currentStepKey) > index,
              }"
              :aria-current="currentStepKey === step.key ? 'step' : undefined"
            >
              <span
                class="portal-booking-progress__number"
                aria-hidden="true"
              />
              <span class="portal-booking-progress__label">
                {{ step.label }}
              </span>
            </span>
          </template>
        </nav>

        <BookingSuccess
          v-if="bookingCompleted && props.bookingResult"
          :message="props.bookingResult.message"
          :service-name="selectedService?.name ?? null"
          :specialist-name="selectedSpecialist?.displayName ?? null"
          :selected-start="selectedStart"
          :timezone="props.availability?.displayTimezone ?? props.query.displayTimezone"
          :locale="locale"
          :format-label="formatLabel(props.query.format)"
          :urls="{ bookings: props.urls.bookings, services: props.urls.services }"
        />

        <BookingChoiceList
          v-else-if="currentStepKey === 'service'"
          heading-id="booking-service-heading"
          :heading="t('booking.chooseService')"
          :choices="serviceChoices"
          :selected-id="selectedServiceId"
          :continue-label="t('booking.continue')"
          :empty-message="t('services.empty')"
          @select="selectedServiceId = $event"
          @continue="continueService"
        />

        <BookingChoiceList
          v-else-if="currentStepKey === 'specialist'"
          heading-id="booking-specialist-heading"
          :heading="t('booking.chooseSpecialist')"
          :choices="specialistChoices"
          :selected-id="selectedSpecialistId"
          :continue-label="t('booking.continue')"
          :change-label="t('booking.changeService')"
          :context-label="t('booking.service')"
          :context-value="selectedService?.name"
          context-test-id="booking-choice-service"
          @select="selectedSpecialistId = $event"
          @continue="continueSpecialist"
          @change="changeService"
        />

        <section
          v-else-if="currentStepKey === 'format'"
          class="portal-booking-choice portal-stack"
          aria-labelledby="booking-format-heading"
        >
          <div class="portal-booking-stage-heading">
            <div class="portal-stack portal-stack--tight">
              <p class="portal-kicker">
                {{ t('booking.format') }}
              </p>
              <h2
                id="booking-format-heading"
                class="portal-heading portal-heading--section"
              >
                {{ t('booking.chooseFormat') }}
              </h2>
            </div>
            <button
              type="button"
              class="portal-link portal-link--button"
              @click="changeService"
            >
              {{ t('booking.changeService') }}
            </button>
          </div>
          <div
            v-if="selectedService"
            class="portal-booking-choice__context"
          >
            <span class="portal-label">{{ t('booking.service') }}</span>
            <strong data-testid="booking-choice-service">{{ selectedService.name }}</strong>
            <template v-if="selectedSpecialist">
              <span class="portal-label">{{ t('booking.specialist') }}</span>
              <strong data-testid="booking-choice-specialist">{{ selectedSpecialist.displayName }}</strong>
            </template>
          </div>
          <div class="portal-format-options">
            <button
              v-for="format in formatOptions"
              :key="format"
              type="button"
              class="portal-format-option"
              :class="{ 'portal-format-option--selected': selectedFormat === format }"
              :aria-pressed="selectedFormat === format"
              @click="selectedFormat = format"
            >
              {{ formatLabel(format) }}
            </button>
          </div>
          <button
            type="button"
            class="portal-button portal-button--primary portal-booking-flow__cta"
            :disabled="selectedFormat === null"
            @click="continueFormat"
          >
            {{ t('booking.continue') }}
          </button>
        </section>

        <template v-else-if="bookingStep === 'time'">
          <section
            v-if="selectedService && (hasSpecialistChoice || formatOptions.length > 1)"
            class="portal-booking-selection-bar"
          >
            <div class="portal-booking-selection-bar__item">
              <span class="portal-label">{{ t('booking.service') }}</span>
              <strong data-testid="booking-selection-service">{{ selectedService.name }}</strong>
            </div>
            <button
              type="button"
              class="portal-link portal-link--button"
              @click="changeService"
            >
              {{ t('booking.changeService') }}
            </button>

            <template v-if="hasSpecialistChoice && selectedSpecialist">
              <div class="portal-booking-selection-bar__item">
                <span class="portal-label">{{ t('booking.specialist') }}</span>
                <strong data-testid="booking-selection-specialist">{{ selectedSpecialist.displayName }}</strong>
              </div>
              <button
                type="button"
                class="portal-link portal-link--button"
                @click="changeSpecialist"
              >
                {{ t('booking.changeSpecialist') }}
              </button>
            </template>

            <template v-if="formatOptions.length > 1">
              <div class="portal-booking-selection-bar__item">
                <span class="portal-label">{{ t('booking.format') }}</span>
                <strong data-testid="booking-selection-format">{{ formatLabel(props.query.format) }}</strong>
              </div>
              <button
                type="button"
                class="portal-link portal-link--button"
                @click="changeFormat"
              >
                {{ t('booking.changeFormat') }}
              </button>
            </template>
          </section>

          <BookingCalendar
            :availability="props.availability"
            :date-from="props.query.dateFrom"
            :date-to="props.query.dateTo"
            :locale="locale"
            :selected-date="selectedDate"
            :selected-start="selectedStart"
            @select-date="selectDate"
            @select-slot="selectSlot"
            @change-month="changeMonth"
          />

          <p
            v-if="bookingError"
            class="portal-notice portal-notice--error"
            role="alert"
          >
            {{ bookingError }}
          </p>

          <button
            type="button"
            class="portal-button portal-button--primary portal-booking-flow__cta"
            :disabled="selectedStart === null"
            @click="continueToConfirmation"
          >
            {{ t('booking.continue') }}
          </button>
        </template>

        <BookingConfirmation
          v-else-if="bookingStep === 'confirmation'"
          :service-name="selectedService?.name ?? null"
          :specialist-name="selectedSpecialist?.displayName ?? null"
          :selected-start="selectedStart"
          :timezone="props.availability?.displayTimezone ?? props.query.displayTimezone"
          :locale="locale"
          :format="props.query.format"
          :format-label="formatLabel(props.query.format)"
          :party-size="bookingForm.party_size"
          :location="bookingForm.location"
          :processing="bookingForm.processing"
          :error="bookingError"
          @update:party-size="bookingForm.party_size = $event"
          @update:location="bookingForm.location = $event"
          @change="returnToTime"
          @confirm="submitBooking"
        />
      </section>
    </section>
  </AppShell>
</template>
