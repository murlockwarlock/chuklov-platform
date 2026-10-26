import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { portalText } from '../locales/portal';
import type { PortalLocale, PortalShell } from '../types/portal';
import { formatPortalDate, formatPortalDateTime } from '../utils/dateTime';

type PortalPageProps = {
    portal: PortalShell;
};

export function usePortalLocale() {
    const page = usePage<PortalPageProps>();
    const locale = computed<PortalLocale>(() => page.props.portal?.locale === 'en' ? 'en' : 'ru');
    const timezone = computed(() => page.props.portal?.timezone ?? 'UTC');
    const datePreferences = computed(() => ({ locale: locale.value === 'en' ? 'en-GB' : 'ru-RU' }));

    return {
        locale,
        formatDate: (value: string | null): string => value
            ? formatPortalDate(value, timezone.value, datePreferences.value)
            : '',
        formatDateTime: (value: string | null): string => value
            ? formatPortalDateTime(value, timezone.value, datePreferences.value)
            : '',
        t: (key: string, values: Record<string, string | number> = {}): string =>
            portalText(locale.value, key, values),
    };
}
