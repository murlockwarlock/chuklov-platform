import type { PortalLocale } from '../types/portal';

const labels: Record<string, Record<PortalLocale, string>> = {
    UTC: { ru: 'UTC', en: 'UTC' },
    'Asia/Almaty': { ru: 'Алматы', en: 'Almaty' },
    'Asia/Aqtau': { ru: 'Актау', en: 'Aktau' },
    'Asia/Atyrau': { ru: 'Атырау', en: 'Atyrau' },
    'Asia/Aqtobe': { ru: 'Актобе', en: 'Aktobe' },
    'Asia/Bangkok': { ru: 'Бангкок', en: 'Bangkok' },
    'Asia/Dubai': { ru: 'Дубай', en: 'Dubai' },
    'Asia/Tashkent': { ru: 'Ташкент', en: 'Tashkent' },
    'Asia/Tokyo': { ru: 'Токио', en: 'Tokyo' },
    'Europe/Berlin': { ru: 'Берлин', en: 'Berlin' },
    'Europe/London': { ru: 'Лондон', en: 'London' },
    'Europe/Moscow': { ru: 'Москва', en: 'Moscow' },
    'Europe/Paris': { ru: 'Париж', en: 'Paris' },
    'America/Los_Angeles': { ru: 'Лос-Анджелес', en: 'Los Angeles' },
    'America/New_York': { ru: 'Нью-Йорк', en: 'New York' },
};

export function timezoneLabel(timezone: string | null | undefined, locale: PortalLocale): string {
    if (!timezone) {
        return '';
    }

    const city = labels[timezone]?.[locale] ?? timezone.split('/').pop()?.replaceAll('_', ' ') ?? timezone;

    return city;
}
