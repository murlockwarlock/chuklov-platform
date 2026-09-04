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
import { formatMajorPrice } from '../../utils/formatMajorPrice';

type VisitFormat = 'office' | 'home' | 'online';
type BookingStep = 'time' | 'confirmation' | 'success';

type ServiceOption = {
    id: number;
    name: string;
    summary: string;
    formats: VisitFormat[];
    durationMinutes: number | null;
    priceMajor: string | null;
    priceCurrency: string | null;
};

type SpecialistOption = {
    id: number;
    displayName: string;
};

type WorkingLocation = {
    id: number;
    name: string;
    address: string;
    timezone: string;
    isDefault: boolean;
    mapUrl: string | null;
};

type LocationDay = {
    areaName: string;
    weekday: number | null;
    specificDate: string | null;
    startTime: string;
    endTime: string;
    timezone: string;
    notes: string | null;
};

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayUtcOffset: string;
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
    formatSelected: boolean;
    displayTimezone: string;
    workingLocationId: number | null;
    locationArea: string | null;
};

type BookingResult = {
    message: string;
    bookingId: number;
    startsAt: string;
} | null;

type LegalDocument = {
    id: number;
    documentType: string;
    title: string;
    content: string;
    contentHtml: string;
    version: string;
    isRequired: boolean;
};

type Props = {
    portal: PortalShell;
    services: ServiceOption[];
    specialists: SpecialistOption[];
    workingLocations: WorkingLocation[];
    locationDays: LocationDay[];
    availability: Availability | null;
    query: BookingQuery;
    timezoneOptions: Array<{ value: string; label: string }>;
    bookingResult: BookingResult;
    legalDocuments: LegalDocument[];
    attribution: { needsManualSource: boolean; url: string; sources: string[] };
    urls: { create: string; store: string; services: string; bookings: string; referrals: string };
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
const selectedStart = ref<string | null>(props.bookingResult?.startsAt ?? null);
const selectedClientTimezone = ref<string>(props.query.displayTimezone);
const selectedWorkingLocationId = ref<number | null>(props.query.workingLocationId);
const selectedLocationArea = ref<string | null>(props.query.locationArea);
const timezoneEditorOpen = ref(false);
const bookingStep = ref<BookingStep>('time');
const bookingForm = useForm<{
    service_id: number | null;
    specialist_id: number | null;
    starts_at: string | null;
    format: VisitFormat;
    party_size: number;
    location: string | null;
    client_timezone: string;
    working_location_id: number | null;
    location_area: string | null;
    latitude: number | null;
    longitude: number | null;
    map_url: string | null;
    consents: Array<{ legal_document_id: number; granted: boolean }>;
    marketing_consent: boolean;
    attribution_source: string | null;
}>({
    service_id: props.query.serviceId,
    specialist_id: props.query.specialistId,
    starts_at: null,
    format: props.query.format,
    party_size: 1,
    location: null,
    client_timezone: props.query.displayTimezone,
    working_location_id: props.query.workingLocationId,
    location_area: props.query.locationArea,
    latitude: null,
    longitude: null,
    map_url: null,
    consents: props.legalDocuments
        .filter((document) => document.isRequired)
        .map((document) => ({ legal_document_id: document.id, granted: false })),
    marketing_consent: false,
    attribution_source: null,
});

const consentValues = ref<Record<number, boolean>>(Object.fromEntries(
    bookingForm.consents.map((consent) => [consent.legal_document_id, consent.granted]),
));
const requiredDocumentTypes = ['offer', 'privacy', 'medical_disclaimer'] as const;
const hasPublishedRequiredDocuments = computed(() => requiredDocumentTypes.every((documentType) => props.legalDocuments.some((document) =>
    document.isRequired && document.documentType === documentType,
)));
const requiredConsentsAccepted = computed(() => props.legalDocuments
    .filter((document) => document.isRequired)
    .every((document) => consentValues.value[document.id] === true) && hasPublishedRequiredDocuments.value);
const requiredConsentAttempted = ref(false);

const acknowledgedBookingId = ref<number | null>(null);

watch(
    () => [
        props.query.serviceId,
        props.query.specialistId,
        props.query.format,
        props.query.formatSelected,
        props.query.dateFrom,
        props.query.displayTimezone,
        props.query.workingLocationId,
        props.query.locationArea,
    ] as const,
    ([serviceId, specialistId, format, formatSelected, dateFrom, displayTimezone, workingLocationId, locationArea]) => {
        selectedServiceId.value = serviceId;
        selectedSpecialistId.value = specialistId;
        selectedFormat.value = formatSelected ? format : null;
        selectedDate.value = dateFrom;
        selectedClientTimezone.value = displayTimezone;
        selectedWorkingLocationId.value = workingLocationId;
        selectedLocationArea.value = locationArea;
        timezoneEditorOpen.value = false;
        bookingForm.service_id = serviceId;
        bookingForm.specialist_id = specialistId;
        bookingForm.format = format;
        bookingForm.client_timezone = displayTimezone;
        bookingForm.working_location_id = workingLocationId;
        bookingForm.location_area = locationArea;

        if (props.bookingResult === null) {
            selectedStart.value = null;
            bookingStep.value = 'time';
            bookingForm.starts_at = null;
        } else {
            selectedStart.value = props.bookingResult.startsAt;
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
        selectedStart.value = props.bookingResult?.startsAt ?? null;
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
const selectedWorkingLocation = computed(() => props.workingLocations.find((location) => location.id === selectedWorkingLocationId.value) ?? null);
const locationAreaOptions = computed(() => Array.from(new Set(props.locationDays.map((locationDay) => locationDay.areaName))));
const hasLocationDayRules = computed(() => props.locationDays.length > 0);
const locationDaySummary = computed(() => {
    if (props.query.format !== 'home' || selectedLocationArea.value === null || selectedLocationArea.value === '') {
        return null;
    }

    const areaDays = props.locationDays.filter((locationDay) => locationDay.areaName === selectedLocationArea.value);
    const weekdays = Array.from(new Set(areaDays
        .map((locationDay) => locationDay.weekday)
        .filter((weekday): weekday is number => weekday !== null)))
        .sort((first, second) => first - second);

    if (weekdays.length > 0) {
        const russianWeekdays = [
            'по понедельникам',
            'по вторникам',
            'по средам',
            'по четвергам',
            'по пятницам',
            'по субботам',
            'по воскресеньям',
        ];
        const englishWeekdays = [
            'on Mondays',
            'on Tuesdays',
            'on Wednesdays',
            'on Thursdays',
            'on Fridays',
            'on Saturdays',
            'on Sundays',
        ];
        const labels = weekdays
            .map((weekday) => (locale.value === 'ru' ? russianWeekdays[weekday - 1] : englishWeekdays[weekday - 1]))
            .filter((label): label is string => label !== undefined)
            .join(', ');

        return t('booking.homeVisitAvailableWeekdays', { area: selectedLocationArea.value, days: labels });
    }

    const dates = areaDays
        .map((locationDay) => locationDay.specificDate)
        .filter((date): date is string => date !== null)
        .sort()
        .slice(0, 3)
        .map((date) => new Intl.DateTimeFormat(locale.value === 'ru' ? 'ru-RU' : 'en-US', {
            day: 'numeric',
            month: 'short',
            timeZone: 'UTC',
        }).format(new Date(`${date}T00:00:00Z`)).replace('.', ''))
        .join(', ');

    return dates === ''
        ? null
        : t('booking.homeVisitAvailableDates', { area: selectedLocationArea.value, dates });
});
const clientTimezoneOffset = computed(() => {
    const value = props.availability?.slots[0]?.startsAt ?? new Date().toISOString();

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: selectedClientTimezone.value,
            timeZoneName: 'longOffset',
        }).formatToParts(new Date(value));
        const offset = parts.find((part) => part.type === 'timeZoneName')?.value ?? '';

        return offset === 'GMT' ? 'UTC+00:00' : offset.replace(/^GMT/, 'UTC');
    } catch {
        return '';
    }
});
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
        ?? errors.location
        ?? errors.working_location_id
        ?? errors.location_area
        ?? errors.client_timezone
        ?? errors.consents
        ?? errors.marketing_consent;
});

function setConsent(id: number, granted: boolean): void {
    consentValues.value[id] = granted;
    const consent = bookingForm.consents.find((item) => item.legal_document_id === id);
    if (consent !== undefined) {
        consent.granted = granted;
    }
    if (requiredConsentsAccepted.value) {
        requiredConsentAttempted.value = false;
    }
}

function setMarketingConsent(granted: boolean): void {
    bookingForm.marketing_consent = granted;
}

function durationLabel(service: ServiceOption): string | null {
    return service.durationMinutes === null
        ? null
        : t('service.durationMinutes', { value: service.durationMinutes });
}

function priceLabel(service: ServiceOption): string | null {
    if (service.priceMajor === null || service.priceCurrency === null) {
        return null;
    }

    return formatMajorPrice(service.priceMajor, service.priceCurrency, locale.value);
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
        display_timezone: selectedClientTimezone.value,
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

    if (selectedWorkingLocationId.value !== null) {
        query.working_location_id = selectedWorkingLocationId.value;
    }

    if (selectedLocationArea.value !== null && selectedLocationArea.value !== '') {
        query.location_area = selectedLocationArea.value;
    }

    return query;
}

function visitBooking(query: Record<string, string | number>, preserveScroll = true): void {
    router.get(props.urls.create, query, {
        preserveState: false,
        preserveScroll,
        replace: true,
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
        display_timezone: selectedClientTimezone.value,
    };

    if (service?.formats.length === 1) {
        query.format = service.formats[0];
    }

    visitBooking(query, false);
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

    visitBooking(query, false);
}

function continueFormat(): void {
    if (selectedFormat.value === null || props.query.serviceId === null) {
        return;
    }

    visitBooking({
        ...bookingQuery(),
        format: selectedFormat.value,
    }, false);
}

function changeService(): void {
    visitBooking({ date_from: props.query.dateFrom, date_to: props.query.dateTo, display_timezone: selectedClientTimezone.value }, false);
}

function changeFormat(): void {
    if (props.query.serviceId === null) {
        return;
    }

    const query: Record<string, string | number> = {
        date_from: props.query.dateFrom,
        date_to: props.query.dateTo,
        service_id: props.query.serviceId,
        display_timezone: selectedClientTimezone.value,
    };

    if (props.query.specialistId !== null) {
        query.specialist_id = props.query.specialistId;
    }

    visitBooking(query, false);
}

function changeSpecialist(): void {
    if (props.query.serviceId === null) {
        return;
    }

    visitBooking({
        date_from: props.query.dateFrom,
        date_to: props.query.dateTo,
        service_id: props.query.serviceId,
        display_timezone: selectedClientTimezone.value,
    }, false);
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

function changeClientTimezone(timezone: string): void {
    if (timezone === '' || timezone === selectedClientTimezone.value) {
        return;
    }

    selectedClientTimezone.value = timezone;
    timezoneEditorOpen.value = false;
    bookingForm.client_timezone = timezone;
    selectedStart.value = null;
    bookingForm.starts_at = null;
    visitBooking(bookingQuery());
}

function changeWorkingLocation(value: string): void {
    const locationId = value === '' ? null : Number(value);
    selectedWorkingLocationId.value = Number.isInteger(locationId) ? locationId : null;
    bookingForm.working_location_id = selectedWorkingLocationId.value;
    bookingForm.location = null;
    selectedStart.value = null;
    bookingForm.starts_at = null;
    visitBooking(bookingQuery());
}

function changeLocationArea(value: string): void {
    selectedLocationArea.value = value === '' ? null : value;
    bookingForm.location_area = selectedLocationArea.value;
    selectedStart.value = null;
    bookingForm.starts_at = null;
    visitBooking(bookingQuery());
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

    if (hasPublishedRequiredDocuments.value && !requiredConsentsAccepted.value) {
        requiredConsentAttempted.value = true;
        bookingStep.value = 'confirmation';

        return;
    }

    bookingForm.service_id = props.query.serviceId;
    bookingForm.specialist_id = props.query.specialistId;
    bookingForm.format = props.query.format;
    bookingForm.starts_at = selectedStart.value;
    bookingForm.client_timezone = selectedClientTimezone.value;
    bookingForm.working_location_id = selectedWorkingLocationId.value;
    bookingForm.location_area = selectedLocationArea.value;
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
          :urls="{ bookings: props.urls.bookings, services: props.urls.services, referrals: props.urls.referrals }"
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
          <h2
            id="booking-time-heading"
            class="portal-heading portal-heading--section portal-booking-time-heading"
          >
            {{ t('booking.chooseDateTime') }}
          </h2>
          <section
            class="portal-booking-context-summary"
            :aria-label="t('booking.chooseDateTime')"
            data-testid="booking-context-summary"
          >
            <div class="portal-booking-context-summary__main">
              <p
                v-if="selectedService"
                class="portal-booking-context-summary__service"
                data-testid="booking-selection-service"
              >
                {{ selectedService.name }}
              </p>
              <p class="portal-booking-context-summary__details">
                <span data-testid="booking-selection-format">{{ formatLabel(props.query.format) }}</span>
                <span v-if="selectedWorkingLocation"> · {{ selectedWorkingLocation.name }}</span>
                <span v-else-if="selectedLocationArea"> · {{ selectedLocationArea }}</span>
              </p>
              <p class="portal-booking-context-summary__timezone">
                {{ t('booking.yourTime') }}:
                <strong data-testid="booking-client-timezone">{{ selectedClientTimezone }}</strong>
                <span v-if="clientTimezoneOffset"> · {{ clientTimezoneOffset }}</span>
              </p>
              <p
                v-if="selectedWorkingLocation"
                class="portal-copy portal-copy--small portal-booking-context-summary__location-copy"
              >
                {{ selectedWorkingLocation.address }} · {{ selectedWorkingLocation.timezone }}
              </p>
              <p
                v-if="locationDaySummary"
                class="portal-copy portal-copy--small"
              >
                {{ locationDaySummary }}
              </p>
            </div>

            <div class="portal-booking-context-summary__actions">
              <button
                type="button"
                class="portal-link portal-link--button"
                @click="changeService"
              >
                {{ t('booking.changeService') }}
              </button>
              <button
                v-if="hasSpecialistChoice && selectedSpecialist"
                type="button"
                class="portal-link portal-link--button"
                @click="changeSpecialist"
              >
                {{ t('booking.changeSpecialist') }}
              </button>
              <button
                v-if="formatOptions.length > 1"
                type="button"
                class="portal-link portal-link--button"
                @click="changeFormat"
              >
                {{ t('booking.changeFormat') }}
              </button>
              <button
                type="button"
                class="portal-link portal-link--button"
                data-testid="booking-client-timezone-edit"
                @click="timezoneEditorOpen = !timezoneEditorOpen"
              >
                {{ t('booking.changeTimezone') }}
              </button>
            </div>

            <div class="portal-booking-context-summary__controls">
              <label
                v-if="props.query.format === 'office' && props.workingLocations.length > 1"
                class="portal-field"
              >
                <span class="portal-label">{{ t('booking.whereMeeting') }}</span>
                <select
                  class="portal-input portal-select"
                  :value="selectedWorkingLocationId ?? ''"
                  data-testid="booking-working-location-select"
                  @change="changeWorkingLocation(($event.target as HTMLSelectElement).value)"
                >
                  <option
                    value=""
                    disabled
                  >{{ t('booking.chooseLocation') }}</option>
                  <option
                    v-for="location in props.workingLocations"
                    :key="location.id"
                    :value="location.id"
                  >{{ location.name }} — {{ location.address }}</option>
                </select>
              </label>
              <label
                v-if="props.query.format === 'home'"
                class="portal-field"
              >
                <span class="portal-label">{{ t('booking.area') }}</span>
                <select
                  v-if="hasLocationDayRules"
                  class="portal-input portal-select"
                  :value="selectedLocationArea ?? ''"
                  data-testid="booking-location-area-select"
                  @change="changeLocationArea(($event.target as HTMLSelectElement).value)"
                >
                  <option
                    value=""
                    disabled
                  >{{ t('booking.chooseArea') }}</option>
                  <option
                    v-for="area in locationAreaOptions"
                    :key="area"
                    :value="area"
                  >{{ area }}</option>
                </select>
                <input
                  v-else
                  :value="selectedLocationArea ?? ''"
                  type="text"
                  maxlength="160"
                  class="portal-input"
                  :placeholder="t('booking.areaPlaceholder')"
                  @change="changeLocationArea(($event.target as HTMLInputElement).value)"
                >
              </label>
              <label
                v-if="timezoneEditorOpen"
                class="portal-field portal-booking-context-summary__timezone-editor"
              >
                <span class="portal-label">{{ t('booking.changeTimezone') }}</span>
                <select
                  class="portal-input portal-select"
                  :value="selectedClientTimezone"
                  data-testid="booking-client-timezone-select"
                  @change="changeClientTimezone(($event.target as HTMLSelectElement).value)"
                >
                  <option
                    v-for="option in props.timezoneOptions"
                    :key="option.value"
                    :value="option.value"
                  >{{ option.label }}</option>
                </select>
              </label>
            </div>

            <p
              v-if="props.query.format === 'home' && !hasLocationDayRules"
              class="portal-copy portal-copy--small"
            >
              {{ t('booking.locationDayNotConfigured') }}
            </p>
          </section>

          <BookingCalendar
            :availability="props.availability"
            :date-from="props.query.dateFrom"
            :date-to="props.query.dateTo"
            :locale="locale"
            :selected-date="selectedDate"
            :selected-start="selectedStart"
            :show-heading="false"
            :auto-select-available-date="props.query.format === 'home' && hasLocationDayRules"
            :show-nearest-dates="props.query.format === 'home'"
            :empty-range-message="props.query.format === 'home' ? t('booking.noHomeSlots') : t('booking.noSlots')"
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
          :working-location="selectedWorkingLocation"
          :location-area="selectedLocationArea"
          :has-location-day-rules="hasLocationDayRules"
          :location-area-options="locationAreaOptions"
          :processing="bookingForm.processing"
          :error="bookingError"
          :legal-documents="props.legalDocuments"
          :consent-values="consentValues"
          :marketing-consent="bookingForm.marketing_consent"
          :show-marketing="props.legalDocuments.some((document) => document.documentType === 'marketing')"
          :attribution-sources="props.attribution.sources"
          :attribution-source="bookingForm.attribution_source"
          :attribution-needs-manual-source="props.attribution.needsManualSource"
          :required-acceptance-error="requiredConsentAttempted && hasPublishedRequiredDocuments ? t('legal.requiredError') : undefined"
          @update:party-size="bookingForm.party_size = $event"
          @update:location="bookingForm.location = $event"
          @update:consent="setConsent"
          @update:marketing-consent="setMarketingConsent"
          @update:attribution-source="bookingForm.attribution_source = $event"
          @change="returnToTime"
          @confirm="submitBooking"
        />
      </section>
    </section>
  </AppShell>
</template>
