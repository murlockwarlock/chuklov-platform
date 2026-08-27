<script setup lang="ts">
import { computed } from 'vue';
import PortalDateTime from '../PortalDateTime.vue';
import { portalText } from '../../locales/portal';
import type { PortalLocale } from '../../types/portal';

type VisitFormat = 'office' | 'home' | 'online';

type AvailabilitySlot = {
    startsAt: string;
    endsAt: string;
    displayUtcOffset: string;
    displayTimezone: string;
    format: VisitFormat;
};

type Availability = {
    displayTimezone: string;
    slots: AvailabilitySlot[];
};

type CalendarDay = {
    key: string;
    number: number;
    inCurrentMonth: boolean;
    inRange: boolean;
    hasSlots: boolean;
};

const props = withDefaults(defineProps<{
    availability: Availability | null;
    dateFrom: string;
    dateTo: string;
    locale: PortalLocale;
    selectedDate: string | null;
    selectedStart: string | null;
    showHeading?: boolean;
}>(), {
    showHeading: true,
});

const emit = defineEmits<{
    selectDate: [date: string];
    selectSlot: [slot: AvailabilitySlot];
    changeMonth: [dateFrom: string, dateTo: string];
}>();

function text(key: string): string {
    return portalText(props.locale, key);
}

function parseDate(value: string): Date | null {
    const [year, month, day] = value.split('-').map(Number);

    if (![year, month, day].every(Number.isInteger)) {
        return null;
    }

    return new Date(Date.UTC(year, month - 1, day));
}

function dateKey(value: Date): string {
    return [value.getUTCFullYear(), value.getUTCMonth() + 1, value.getUTCDate()]
        .map((part, index) => index === 0 ? String(part) : String(part).padStart(2, '0'))
        .join('-');
}

function localSlotDate(value: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        day: '2-digit',
        month: '2-digit',
        timeZone: props.availability?.displayTimezone,
        year: 'numeric',
    }).formatToParts(new Date(value));
    const part = (type: 'year' | 'month' | 'day'): string =>
        parts.find((item) => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

const slotsByDate = computed(() => {
    const groups = new Map<string, AvailabilitySlot[]>();

    for (const slot of props.availability?.slots ?? []) {
        const key = localSlotDate(slot.startsAt);
        const slots = groups.get(key) ?? [];
        slots.push(slot);
        groups.set(key, slots);
    }

    return groups;
});

const availableDateKeys = computed(() => new Set(slotsByDate.value.keys()));
const monthStart = computed(() => {
    const date = parseDate(props.dateFrom);

    return date === null ? null : new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
});

const monthLabel = computed(() => {
    if (monthStart.value === null) {
        return '';
    }

    return new Intl.DateTimeFormat(props.locale === 'ru' ? 'ru-RU' : 'en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(monthStart.value);
});

const weekdayLabels = computed(() => {
    const monday = new Date(Date.UTC(2026, 0, 5));
    const formatter = new Intl.DateTimeFormat(props.locale === 'ru' ? 'ru-RU' : 'en-US', {
        weekday: 'short',
        timeZone: 'UTC',
    });

    return Array.from({ length: 7 }, (_, index) => {
        const date = new Date(monday.getTime());
        date.setUTCDate(monday.getUTCDate() + index);

        return formatter.format(date).replace('.', '');
    });
});

const calendarDays = computed<CalendarDay[]>(() => {
    if (monthStart.value === null) {
        return [];
    }

    const daysInMonth = new Date(
        Date.UTC(monthStart.value.getUTCFullYear(), monthStart.value.getUTCMonth() + 1, 0),
    ).getUTCDate();
    const mondayOffset = (monthStart.value.getUTCDay() + 6) % 7;
    const totalCells = Math.ceil((mondayOffset + daysInMonth) / 7) * 7;
    const days: CalendarDay[] = [];

    for (let index = 0; index < totalCells; index += 1) {
        const date = new Date(monthStart.value.getTime());
        date.setUTCDate(index - mondayOffset + 1);
        const key = dateKey(date);

        days.push({
            key,
            number: date.getUTCDate(),
            inCurrentMonth: date.getUTCMonth() === monthStart.value.getUTCMonth(),
            inRange: key >= props.dateFrom && key <= props.dateTo,
            hasSlots: availableDateKeys.value.has(key),
        });
    }

    return days;
});

const selectedDayKey = computed(() => {
    if (props.selectedDate !== null && availableDateKeys.value.has(props.selectedDate)) {
        return props.selectedDate;
    }

    return Array.from(availableDateKeys.value).at(0) ?? null;
});

const selectedDaySlots = computed(() =>
    selectedDayKey.value === null ? [] : slotsByDate.value.get(selectedDayKey.value) ?? [],
);

const selectedDayLabel = computed(() => {
    const date = parseDate(selectedDayKey.value ?? props.dateFrom);

    if (date === null) {
        return '';
    }

    const label = new Intl.DateTimeFormat(props.locale === 'ru' ? 'ru-RU' : 'en-US', {
        day: 'numeric',
        month: 'long',
        weekday: 'long',
        timeZone: 'UTC',
    }).format(date);

    return label.charAt(0).toUpperCase() + label.slice(1);
});

const previousMonth = computed(() => {
    if (monthStart.value === null) {
        return null;
    }

    const date = new Date(Date.UTC(monthStart.value.getUTCFullYear(), monthStart.value.getUTCMonth() - 1, 1));

    return {
        from: dateKey(date),
        to: dateKey(new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0))),
    };
});

const nextMonth = computed(() => {
    if (monthStart.value === null) {
        return null;
    }

    const date = new Date(Date.UTC(monthStart.value.getUTCFullYear(), monthStart.value.getUTCMonth() + 1, 1));

    return {
        from: dateKey(date),
        to: dateKey(new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0))),
    };
});

function selectDate(day: CalendarDay): void {
    if (!day.inRange || !day.hasSlots) {
        return;
    }

    emit('selectDate', day.key);
}

function selectSlot(slot: AvailabilitySlot): void {
    emit('selectDate', selectedDayKey.value ?? localSlotDate(slot.startsAt));
    emit('selectSlot', slot);
}
</script>

<template>
  <section
    class="portal-booking-calendar portal-stack"
    :aria-labelledby="props.showHeading ? 'booking-calendar-heading' : undefined"
    :aria-label="props.showHeading ? undefined : text('booking.chooseNewDateTime')"
  >
    <header
      v-if="props.showHeading"
      class="portal-stack portal-stack--tight"
    >
      <h2
        id="booking-calendar-heading"
        class="portal-heading portal-heading--section"
      >
        {{ text('booking.chooseDateTime') }}
      </h2>
    </header>

    <div class="portal-booking-calendar__layout">
      <section class="portal-calendar-card">
        <header class="portal-calendar-card__header">
          <button
            type="button"
            class="portal-calendar-card__nav"
            :aria-label="text('booking.previousMonth')"
            :disabled="previousMonth === null"
            @click="previousMonth && emit('changeMonth', previousMonth.from, previousMonth.to)"
          >
            ‹
          </button>
          <p class="portal-calendar-card__month">
            {{ monthLabel }}
          </p>
          <button
            type="button"
            class="portal-calendar-card__nav"
            :aria-label="text('booking.nextMonth')"
            :disabled="nextMonth === null"
            @click="nextMonth && emit('changeMonth', nextMonth.from, nextMonth.to)"
          >
            ›
          </button>
        </header>

        <div
          class="portal-calendar-card__weekdays"
          aria-hidden="true"
        >
          <span
            v-for="weekday in weekdayLabels"
            :key="weekday"
          >
            {{ weekday }}
          </span>
        </div>

        <div class="portal-calendar-card__days">
          <button
            v-for="day in calendarDays"
            :key="day.key"
            type="button"
            class="portal-calendar-card__day"
            :class="{
              'portal-calendar-card__day--outside': !day.inCurrentMonth,
              'portal-calendar-card__day--available': day.inCurrentMonth && day.hasSlots,
              'portal-calendar-card__day--selected': selectedDayKey === day.key,
            }"
            :disabled="!day.inRange || !day.hasSlots"
            :aria-label="day.key"
            :aria-pressed="selectedDayKey === day.key"
            @click="selectDate(day)"
          >
            {{ day.number }}
          </button>
        </div>
      </section>

      <section
        class="portal-time-card"
        aria-labelledby="booking-times-heading"
      >
        <header class="portal-time-card__header">
          <p class="portal-kicker">
            {{ selectedDayLabel }}
          </p>
          <p class="portal-copy portal-copy--small">
            {{ text('booking.startTimeOnly') }}
          </p>
        </header>

        <p
          v-if="selectedDaySlots.length === 0"
          class="portal-notice"
          role="status"
        >
          {{ text('booking.noSlotsForDay') }}
        </p>
        <div
          v-else
          id="booking-times-heading"
          class="portal-time-grid"
          :aria-label="text('booking.chooseTime')"
        >
          <button
            v-for="slot in selectedDaySlots"
            :key="slot.startsAt"
            type="button"
            class="portal-time-button"
            data-testid="availability-slot"
            :aria-pressed="props.selectedStart === slot.startsAt"
            @click="selectSlot(slot)"
          >
            <PortalDateTime
              :value="slot.startsAt"
              :time-zone="slot.displayTimezone"
              :locale="props.locale"
              mode="time"
            />
            <span>UTC{{ slot.displayUtcOffset }}</span>
          </button>
        </div>
      </section>
    </div>

    <p
      v-if="props.availability && props.availability.slots.length === 0"
      class="portal-notice"
      role="status"
    >
      {{ text('booking.noSlots') }}
    </p>
  </section>
</template>
