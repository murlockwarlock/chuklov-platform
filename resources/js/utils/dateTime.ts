export type PortalDateTimePreferences = {
    locale: string;
    dateOrder: 'day-month-year' | 'month-day-year' | 'year-month-day';
    dateSeparator: string;
    timeCycle: 'h23' | 'h12';
};

export const defaultPortalDateTimePreferences: PortalDateTimePreferences = {
    locale: 'en-GB',
    dateOrder: 'day-month-year',
    dateSeparator: '-',
    timeCycle: 'h23',
};

type DatePart = 'day' | 'month' | 'year';

function toDate(value: string | Date | null): Date | null {
    if (value === null) {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function preferencesWithDefaults(
    preferences: Partial<PortalDateTimePreferences> = {},
): PortalDateTimePreferences {
    return { ...defaultPortalDateTimePreferences, ...preferences };
}

export function formatPortalDate(
    value: string | Date | null,
    timeZone: string,
    preferences: Partial<PortalDateTimePreferences> = {},
): string {
    const date = toDate(value);

    if (date === null) {
        return '';
    }

    const resolved = preferencesWithDefaults(preferences);
    const parts = new Intl.DateTimeFormat(resolved.locale, {
        day: '2-digit',
        month: '2-digit',
        timeZone,
        year: 'numeric',
    }).formatToParts(date);
    const values = Object.fromEntries(
        parts
            .filter((part) => ['day', 'month', 'year'].includes(part.type))
            .map((part) => [part.type, part.value]),
    ) as Record<DatePart, string>;
    const order: Record<PortalDateTimePreferences['dateOrder'], DatePart[]> = {
        'day-month-year': ['day', 'month', 'year'],
        'month-day-year': ['month', 'day', 'year'],
        'year-month-day': ['year', 'month', 'day'],
    };

    return order[resolved.dateOrder]
        .map((part) => values[part])
        .join(resolved.dateSeparator);
}

export function formatPortalTime(
    value: string | Date | null,
    timeZone: string,
    preferences: Partial<PortalDateTimePreferences> = {},
): string {
    const date = toDate(value);

    if (date === null) {
        return '';
    }

    const resolved = preferencesWithDefaults(preferences);
    const parts = new Intl.DateTimeFormat(resolved.locale, {
        hour: '2-digit',
        hourCycle: resolved.timeCycle,
        minute: '2-digit',
        timeZone,
    }).formatToParts(date);
    const hour = parts.find((part) => part.type === 'hour')?.value ?? '';
    const minute = parts.find((part) => part.type === 'minute')?.value ?? '';
    const dayPeriod = parts.find((part) => part.type === 'dayPeriod')?.value;

    return dayPeriod ? `${hour}:${minute} ${dayPeriod}` : `${hour}:${minute}`;
}

export function formatPortalDateTime(
    value: string | Date | null,
    timeZone: string,
    preferences: Partial<PortalDateTimePreferences> = {},
): string {
    const date = formatPortalDate(value, timeZone, preferences);
    const time = formatPortalTime(value, timeZone, preferences);

    return date && time ? `${date} ${time}` : date || time;
}
